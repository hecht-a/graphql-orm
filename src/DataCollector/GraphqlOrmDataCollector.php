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

    public function __construct(
        private readonly QueryRedactor $redactor = new QueryRedactor(),
    ) {
    }

    public function addQuery(
        GraphqlQueryTrace $trace,
    ): void {
        $queries = $this->getQueries();
        $queries[] = [
            'graphql' => $trace->graphql,
            // The query with its values masked: queries differing only by their values share it.
            'shape' => $this->redactor->redactQuery($trace->graphql),
            'ast' => $trace->ast,
            'variables' => $trace->variables,
            'endpoint' => $trace->endpoint,
            'caller' => $trace->caller,
            'response_size' => $trace->responseSize,
            'errors' => $trace->errors,
            'hydrated_count' => $trace->hydratedCount,
            'depth_used' => $trace->hydrationMaxDepth,
            'hydrated_collections' => $trace->hydratedCollections,
            'hydrated_entities' => $trace->hydratedEntities,
            'hydrated_relations' => $trace->hydratedRelations,
            'duration' => $trace->duration,
        ];
        $this->data['queries'] = $queries;
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
