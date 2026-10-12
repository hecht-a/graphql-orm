<?php

declare(strict_types=1);

namespace GraphqlOrm\Client;

use GraphqlOrm\Exception\GraphqlRequestException;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class GraphqlClient implements GraphqlClientInterface
{
    private const FIRST_HTTP_ERROR_STATUS = 400;

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
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function query(string $query, GraphqlExecutionContext $context, array $variables = [],
    ): array {
        $eventName = 'graphql_orm.query.' . $context->trace->id;

        $context->trace->caller = $this->findCaller(
            debug_backtrace(
                DEBUG_BACKTRACE_IGNORE_ARGS,
                15
            )
        );

        $context->trace->endpoint = $this->endpoint;

        $this->stopwatch?->start($eventName, 'graphql_orm');

        try {
            $response = $this
                ->httpClient
                ->request(
                    'POST',
                    $this->endpoint,
                    [
                        'headers' => $this->headers,
                        'json' => [
                            'query' => $query,
                            'variables' => $variables,
                        ],
                        ...$this->httpClientOptions,
                    ]
                );

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw GraphqlRequestException::transportFailed($this->endpoint, $e);
        } finally {
            $event = $this->stopwatch?->stop($eventName);
            $context->trace->duration = $event ? $event->getDuration() : 0.0;
        }

        $context->trace->responseSize = \strlen($content);

        if ($statusCode >= self::FIRST_HTTP_ERROR_STATUS) {
            throw GraphqlRequestException::httpError($this->endpoint, $statusCode);
        }

        return $this->decode($content, $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $content, GraphqlExecutionContext $context): array
    {
        try {
            $result = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw GraphqlRequestException::invalidJson($this->endpoint, $e);
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
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;

            if (!\is_string($file)) {
                continue;
            }

            if (str_contains($file, '/vendor/')) {
                continue;
            }

            if (str_contains($file, 'graphql-orm')) {
                continue;
            }

            $line = $frame['line'] ?? null;

            if (!\is_int($line)) {
                $line = null;
            }

            $class = $frame['class'] ?? null;

            if (!\is_string($class)) {
                $class = null;
            }

            $function = $frame['function'] ?? null;

            if (!\is_string($function)) {
                $function = null;
            }

            return [
                'file' => $this->shortenPath($file),
                'line' => $line,
                'class' => $class,
                'function' => $function,
            ];
        }

        return null;
    }

    private function shortenPath(string $path): string
    {
        $projectDir = $_SERVER['APP_RUNTIME_OPTIONS']['project_dir']
            ?? \dirname(__DIR__, 3);

        if (str_starts_with($path, $projectDir)) {
            return substr($path, \strlen($projectDir) + 1);
        }

        return $path;
    }
}
