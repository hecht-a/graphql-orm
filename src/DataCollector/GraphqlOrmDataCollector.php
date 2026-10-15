<?php

declare(strict_types=1);

namespace GraphqlOrm\DataCollector;

use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Query\GraphqlQueryTrace;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

/**
 * @internal
 */
final class GraphqlOrmDataCollector extends DataCollector
{
    /**
     * Queries of a same shape sent this many times are reported, as the sign of a loop (an N+1 problem).
     */
    public const REPEATED_QUERY_THRESHOLD = 3;

    /**
     * @param float|null $slowQueryThreshold duration in milliseconds from which a query is reported as slow
     */
    public function __construct(
        private readonly QueryRedactor $redactor = new QueryRedactor(),
        private readonly ?float $slowQueryThreshold = null,
    ) {
    }

    public function addQuery(
        GraphqlQueryTrace $trace,
    ): void {
        $queries = $this->getQueries();
        $queries[] = [
            'graphql' => $trace->graphql,
            'root_field' => self::rootField($trace->graphql),
            // The query with its values masked: queries differing only by their values share it.
            'shape' => $this->redactor->redactQuery($trace->graphql),
            'ast' => $trace->ast,
            'variables' => $trace->variables,
            'endpoint' => $trace->endpoint,
            'curl' => $trace->endpoint !== null ? self::curl($trace->endpoint, $trace->graphql, $trace->variables) : null,
            'caller' => $trace->caller,
            'response_size' => $trace->responseSize,
            'errors' => $trace->errors,
            'hydrated_count' => $trace->hydratedCount,
            'depth_used' => $trace->hydrationMaxDepth,
            'hydrated_collections' => $trace->hydratedCollections,
            'hydrated_entities' => $trace->hydratedEntities,
            'hydrated_relations' => $trace->hydratedRelations,
            'duration' => $trace->duration,
            'slow' => $this->slowQueryThreshold !== null && $trace->duration >= $this->slowQueryThreshold,
        ];
        $this->data['queries'] = $queries;
    }

    /**
     * The first field selected by the query, e.g. "tasks" for `query($first: Int) { tasks(first: $first) { id } }`.
     */
    private static function rootField(string $graphql): ?string
    {
        return preg_match('/\{\s*(\w+)/', $graphql, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * The query as a cURL command, to replay it from a terminal. Headers are not recorded: authentication is to be added.
     *
     * @param array<mixed> $variables
     */
    private static function curl(string $endpoint, string $graphql, array $variables): string
    {
        $body = json_encode(
            ['query' => $graphql, 'variables' => $variables === [] ? new \stdClass() : $variables],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return \sprintf('curl -X POST %s -H %s --data-raw %s', self::shellQuote($endpoint), self::shellQuote('Content-Type: application/json'), self::shellQuote($body));
    }

    private static function shellQuote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    public function collect(
        Request $request,
        Response $response,
        ?\Throwable $exception = null,
    ): void {
        $this->data['queries'] ??= [];
    }

    public function reset(): void
    {
        $this->data = [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getQueries(): array
    {
        /** @var array<int, array<string, mixed>> $queries */
        $queries = $this->data['queries'] ?? [];

        return $queries;
    }

    public function getSlowQueryCount(): int
    {
        return \count(array_filter($this->getQueries(), fn (array $query) => ($query['slow'] ?? false) === true));
    }

    public function getSlowQueryThreshold(): ?float
    {
        return $this->slowQueryThreshold;
    }

    public function getQueryCount(): int
    {
        return \count($this->getQueries());
    }

    public function getTotalDuration(): float
    {
        $total = 0.0;

        foreach ($this->getQueries() as $query) {
            $duration = $query['duration'] ?? 0;
            $total += \is_int($duration) || \is_float($duration) ? $duration : 0;
        }

        return $total;
    }

    /**
     * The shapes of queries sent at least REPEATED_QUERY_THRESHOLD times, with the numbers of these queries (from 1).
     *
     * @return list<array{shape: string, count: int, queries: list<int>}>
     */
    public function getRepeatedQueries(): array
    {
        $queriesByShape = [];

        foreach ($this->getQueries() as $index => $query) {
            $shape = $query['shape'] ?? null;

            if (\is_string($shape)) {
                $queriesByShape[$shape][] = $index + 1;
            }
        }

        $repeated = [];

        foreach ($queriesByShape as $shape => $queries) {
            if (\count($queries) >= self::REPEATED_QUERY_THRESHOLD) {
                $repeated[] = ['shape' => (string) $shape, 'count' => \count($queries), 'queries' => $queries];
            }
        }

        return $repeated;
    }

    public function getHydratedEntityCount(): int
    {
        return array_sum(array_map(fn (array $query): int => \is_int($query['hydrated_entities'] ?? null) ? $query['hydrated_entities'] : 0, $this->getQueries()));
    }

    /**
     * @return int the bytes of all the responses
     */
    public function getResponseSize(): int
    {
        return array_sum(array_map(fn (array $query): int => \is_int($query['response_size'] ?? null) ? $query['response_size'] : 0, $this->getQueries()));
    }

    public function getErrorCount(): int
    {
        $count = 0;

        foreach ($this->getQueries() as $query) {
            if (!empty($query['errors'])) {
                ++$count;
            }
        }

        return $count;
    }

    public function getName(): string
    {
        return 'graphql_orm';
    }
}
