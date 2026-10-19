<?php

declare(strict_types=1);

namespace GraphqlOrm\Client;

use GraphqlOrm\Exception\GraphqlRequestException;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Mapping\Entity;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Twig\Template;

final readonly class GraphqlClient implements ConcurrentGraphqlClientInterface
{
    private const FIRST_HTTP_ERROR_STATUS = 400;

    /** Frames read to find the caller: deep enough for a relation loaded on first access from a Twig template. */
    private const CALLER_STACK_DEPTH = 40;

    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $httpClientOptions
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $endpoint,
        private array $headers = [],
        private array $httpClientOptions = [],
        private ?Stopwatch $stopwatch = null,
        private ?string $projectDir = null,
        private ?HeadersProviderInterface $headersProvider = null,
        private ?PersistedQueries $persistedQueries = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function query(string $query, GraphqlExecutionContext $context, array $variables = []): array
    {
        return $this->send($query, $context, $variables)();
    }

    public function send(string $query, GraphqlExecutionContext $context, array $variables = []): \Closure
    {
        $eventName = 'graphql_orm.query.' . $context->trace->id;

        $context->trace->caller = $this->findCaller(
            debug_backtrace(
                DEBUG_BACKTRACE_IGNORE_ARGS,
                self::CALLER_STACK_DEPTH
            )
        );

        $context->trace->endpoint = $this->endpoint;
        // An empty PHP array is encoded as a JSON list, which servers such as Hot Chocolate (Data API Builder) reject:
        // variables must be a JSON object.
        $variables = $variables === [] ? new \stdClass() : $variables;
        $persisted = $this->persistedQueries?->isSupported() === true ? $this->persistedQueries : null;
        // Measured without the stopwatch too, which the application may not provide.
        $startedAt = hrtime(true);
        $response = $this->post($persisted !== null ? ['variables' => $variables, 'extensions' => $persisted->extensions($query)] : ['query' => $query, 'variables' => $variables], $eventName, $startedAt, $context);

        return function () use ($response, $eventName, $startedAt, $context, $persisted, $query, $variables): array {
            $result = $this->receive($response, $eventName, $startedAt, $context, $persisted !== null);

            if (\is_array($result)) {
                $context->trace->persistedQuery = $persisted !== null ? 'hit' : null;

                return $result;
            }

            // The server asks for the text of the query, or refuses the hash: it does not support the persisted queries.
            if ($result) {
                $json = ['query' => $query, 'variables' => $variables, 'extensions' => $persisted?->extensions($query)];
                $context->trace->persistedQuery = 'miss';
            } else {
                $persisted?->unsupported();
                $json = ['query' => $query, 'variables' => $variables];
            }

            $waited = $context->trace->duration;
            $startedAt = hrtime(true);
            $answer = $this->receive($this->post($json, $eventName, $startedAt, $context), $eventName, $startedAt, $context);
            $context->trace->duration += $waited;

            return \is_array($answer) ? $answer : throw GraphqlRequestException::graphqlErrors([['message' => 'The server did not accept the query sent in full.']]);
        };
    }

    /**
     * Sends a request: reading its response, later, is what waits for it.
     *
     * @param array<string, mixed> $json
     *
     * @throws GraphqlRequestException when it cannot be sent
     */
    private function post(array $json, string $eventName, int|float $startedAt, GraphqlExecutionContext $context): ResponseInterface
    {
        $this->stopwatch?->start($eventName, 'graphql_orm');

        try {
            return $this->httpClient->request('POST', $this->endpoint, [
                'headers' => $this->headers(),
                'json' => $json,
                ...$this->httpClientOptions,
            ]);
        } catch (TransportExceptionInterface $e) {
            $this->stopTimer($eventName, $startedAt, $context);

            throw GraphqlRequestException::transportFailed($this->endpoint, $e);
        }
    }

    /**
     * @param bool $persisted whether the request sent the hash of the query instead of its text
     *
     * @return array<string, mixed>|bool the response, or whether the server asks for the text of the query (true) or
     *                                   does not support the persisted queries (false)
     */
    private function receive(ResponseInterface $response, string $eventName, int|float $startedAt, GraphqlExecutionContext $context, bool $persisted = false): array|bool
    {
        try {
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw GraphqlRequestException::transportFailed($this->endpoint, $e);
        } finally {
            $this->stopTimer($eventName, $startedAt, $context);
        }

        $context->trace->responseSize = \strlen($content);

        // Decoded once, whatever reads it: the body may be large.
        try {
            $body = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
            $invalidJson = null;
        } catch (\JsonException $e) {
            $body = null;
            $invalidJson = $e;
        }

        // Answered with a 200 by Apollo, with a 400 by other servers.
        if ($persisted && null !== $failure = PersistedQueries::failureOf(\is_array($body) ? $body : null)) {
            return $failure;
        }

        if ($statusCode >= self::FIRST_HTTP_ERROR_STATUS) {
            // Servers such as Data API Builder and PostGraphile answer an invalid query with a 400 holding its errors.
            $errors = self::errorsOf($body);
            $context->trace->errors = $errors !== [] ? $errors : null;

            throw GraphqlRequestException::httpError($this->endpoint, $statusCode, $errors);
        }

        return $this->decode($body, $invalidJson, $context);
    }

    private function stopTimer(string $eventName, int|float $startedAt, GraphqlExecutionContext $context): void
    {
        $this->stopwatch?->stop($eventName);
        $context->trace->duration = (hrtime(true) - $startedAt) / 1e6;
    }

    /**
     * @return array<string, mixed>
     */
    private function headers(): array
    {
        if ($this->headersProvider === null) {
            return $this->headers;
        }

        return [...$this->headers, ...$this->headersProvider->getHeaders()];
    }

    /**
     * The GraphQL errors of an HTTP error body, or none when it holds none (an HTML error page, for instance).
     *
     * @return list<mixed>
     */
    private static function errorsOf(mixed $body): array
    {
        $errors = \is_array($body) ? $body['errors'] ?? null : null;

        return \is_array($errors) && array_is_list($errors) ? $errors : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $result, ?\JsonException $invalidJson, GraphqlExecutionContext $context): array
    {
        if ($invalidJson !== null) {
            throw GraphqlRequestException::invalidJson($this->endpoint, $invalidJson);
        }

        if (!\is_array($result)) {
            throw InvalidGraphqlResponseException::expectedArray($result);
        }

        $errors = $result['errors'] ?? null;
        $context->trace->errors = \is_array($errors) ? $errors : null;

        // A response may carry partial data alongside errors: only fail when there is nothing to hydrate.
        if ($context->trace->errors !== null && ($result['data'] ?? null) === null) {
            throw GraphqlRequestException::graphqlErrors($context->trace->errors);
        }

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * Finds the first call made from application code: its file and line, and the class and function it was made from.
     *
     * @param array<int, array<string, mixed>> $trace
     *
     * @return array{
     *   file: string,
     *   line: int|null,
     *   class: string|null,
     *   function: string|null
     * }|null
     */
    private function findCaller(array $trace): ?array
    {
        foreach ($trace as $index => $frame) {
            $file = $frame['file'] ?? null;

            if (!\is_string($file) || $this->isLibraryFile($file)) {
                continue;
            }

            $line = $frame['line'] ?? null;
            $callingFrame = $trace[$index + 1] ?? [];
            $class = $callingFrame['class'] ?? null;
            $function = $callingFrame['function'] ?? null;

            // A method of an entity, e.g. __toString() reading a relation loaded on first access: its caller tells more.
            if (\is_string($class) && self::isEntity($class)) {
                continue;
            }

            $template = \is_string($class) && \is_int($line) ? self::templateSource($class, $line) : null;

            if ($template !== null) {
                return ['file' => $template[0], 'line' => $template[1], 'class' => null, 'function' => null];
            }

            return [
                'file' => $this->shortenPath($file),
                'line' => \is_int($line) ? $line : null,
                'class' => \is_string($class) ? $class : null,
                'function' => \is_string($function) ? $function : null,
            ];
        }

        return null;
    }

    private static function isEntity(string $class): bool
    {
        return class_exists($class) && (new \ReflectionClass($class))->getAttributes(Entity::class) !== [];
    }

    /**
     * The template and its line, for a line of a Twig template compiled in the cache: Twig maps the lines of the code it
     * generates to those of the template. Twig is optional: without it, no class is a template.
     *
     * @return array{string, int|null}|null
     */
    private static function templateSource(string $class, int $line): ?array
    {
        if (!class_exists($class) || !is_subclass_of($class, Template::class)) {
            return null;
        }

        // The name and the line map of a compiled template are constants of its code: no environment is needed.
        $template = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $lines = $template->getDebugInfo();
        krsort($lines);

        foreach ($lines as $codeLine => $templateLine) {
            if ($codeLine <= $line) {
                return [$template->getTemplateName(), $templateLine];
            }
        }

        return [$template->getTemplateName(), null];
    }

    private function isLibraryFile(string $file): bool
    {
        return str_contains($file, \DIRECTORY_SEPARATOR . 'vendor' . \DIRECTORY_SEPARATOR)
            || str_starts_with($file, \dirname(__DIR__) . \DIRECTORY_SEPARATOR);
    }

    private function shortenPath(string $path): string
    {
        if ($this->projectDir !== null && str_starts_with($path, $this->projectDir . \DIRECTORY_SEPARATOR)) {
            return substr($path, \strlen($this->projectDir) + 1);
        }

        return $path;
    }
}
