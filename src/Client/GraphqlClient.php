<?php

declare(strict_types=1);

namespace GraphqlOrm\Client;

use GraphqlOrm\Execution\GraphqlExecutionContext;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class GraphqlClient implements GraphqlClientInterface
{
    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $httpClientOptions
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $endpoint,
        private array $headers = [],
        private array $httpClientOptions = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function query(string $query, GraphqlExecutionContext $context, array $variables = [],
    ): array {
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

        $context->trace->caller = $this->findCaller(
            debug_backtrace(
                DEBUG_BACKTRACE_IGNORE_ARGS,
                15
            )
        );

        $context->trace->endpoint = $this->endpoint;

        $content = $response->getContent(false);

        $context->trace->responseSize = \strlen($content);

        /** @var array{'errors': array<string|int, mixed>|null} $result */
        $result = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        $context->trace->errors = $result['errors'] ?? null;

        return $response->toArray();
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
