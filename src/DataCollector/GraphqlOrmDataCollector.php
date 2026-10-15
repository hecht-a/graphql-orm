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

    private const LAZY_ORIGIN = 'lazy';

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
            'started_at' => $trace->startedAt,
            'origin' => $trace->origin,
            'entity_class' => $trace->entityClass,
            'lazy_relation' => $trace->lazyRelation,
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
     * Records an entity that find() served from the shared identity map, without a query.
     *
     * @param string|null $caller the file and line that called find(), e.g. src/Controller/TaskController.php:51
     */
    public function addIdentityMapHit(string $class, int|string $identifier, ?string $caller): void
    {
        $hits = $this->getIdentityMapHits();

        foreach ($hits as $index => $hit) {
            if ($hit['class'] === $class && $hit['identifier'] === $identifier) {
                ++$hits[$index]['count'];
                $hits[$index]['callers'] = $caller !== null ? array_values(array_unique([...$hit['callers'], $caller])) : $hit['callers'];
                $this->data['identity_map_hits'] = $hits;

                return;
            }
        }

        $hits[] = ['class' => $class, 'identifier' => $identifier, 'count' => 1, 'callers' => $caller !== null ? [$caller] : []];
        $this->data['identity_map_hits'] = $hits;
    }

    /**
     * The entities served from the shared identity map, each with the number of times and the lines calling find().
     *
     * @return list<array{class: string, identifier: int|string, count: int, callers: list<string>}>
     */
    public function getIdentityMapHits(): array
    {
        /** @var list<array{class: string, identifier: int|string, count: int, callers: list<string>}> $hits */
        $hits = $this->data['identity_map_hits'] ?? [];

        return $hits;
    }

    /**
     * @return int the queries saved by the shared identity map
     */
    public function getIdentityMapHitCount(): int
    {
        return array_sum(array_column($this->getIdentityMapHits(), 'count'));
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
        $startedAt = $request->server->get('REQUEST_TIME_FLOAT');
        $this->data['request_started_at'] = \is_float($startedAt) || \is_int($startedAt) ? (float) $startedAt : null;
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

            // The lazy loads are grouped by relation instead: see getLazyLoadsByRelation().
            if (\is_string($shape) && ($query['origin'] ?? null) !== self::LAZY_ORIGIN) {
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

    /**
     * When each query started, in milliseconds since the request started, or since the first query when unknown.
     *
     * @return list<float>
     */
    public function getQueryOffsets(): array
    {
        $startedAts = array_map(fn (array $query): ?float => \is_float($query['started_at'] ?? null) ? $query['started_at'] : null, $this->getQueries());
        $known = array_filter($startedAts, fn (?float $startedAt): bool => $startedAt !== null);
        $origin = $this->data['request_started_at'] ?? ($known !== [] ? min($known) : null);

        return array_values(array_map(
            fn (?float $startedAt): float => $startedAt !== null && \is_float($origin) ? max(0.0, ($startedAt - $origin) * 1000) : 0.0,
            $startedAts,
        ));
    }

    /**
     * @return float the milliseconds from the start of the timeline to the end of the last query
     */
    public function getTimelineSpan(): float
    {
        $span = 0.0;

        foreach ($this->getQueryOffsets() as $index => $offset) {
            $duration = $this->getQueries()[$index]['duration'] ?? 0;
            $span = max($span, $offset + (\is_int($duration) || \is_float($duration) ? $duration : 0));
        }

        return $span;
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

    /**
     * The relations loaded on first access at least REPEATED_QUERY_THRESHOLD times, one query each: the N+1 problem of
     * lazy loading. With the numbers of their queries (from 1), their total duration and the lines reading them.
     *
     * @return list<array{relation: string, count: int, queries: list<int>, duration: float, callers: list<string>}>
     */
    public function getLazyLoadsByRelation(): array
    {
        $byRelation = [];

        foreach ($this->getQueries() as $index => $query) {
            $relation = $query['lazy_relation'] ?? null;

            if (($query['origin'] ?? null) !== self::LAZY_ORIGIN || !\is_string($relation)) {
                continue;
            }

            $duration = $query['duration'] ?? 0;
            $caller = $query['caller'] ?? null;
            $group = $byRelation[$relation] ?? ['relation' => $relation, 'count' => 0, 'queries' => [], 'duration' => 0.0, 'callers' => []];
            ++$group['count'];
            $group['queries'][] = $index + 1;
            $group['duration'] += \is_int($duration) || \is_float($duration) ? $duration : 0;

            if (\is_array($caller) && \is_string($caller['file'] ?? null)) {
                $line = $caller['file'] . (\is_int($caller['line'] ?? null) ? ':' . $caller['line'] : '');
                $group['callers'] = array_values(array_unique([...$group['callers'], $line]));
            }

            $byRelation[$relation] = $group;
        }

        return array_values(array_filter($byRelation, fn (array $group): bool => $group['count'] >= self::REPEATED_QUERY_THRESHOLD));
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
