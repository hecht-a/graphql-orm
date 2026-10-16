<?php

declare(strict_types=1);

namespace GraphqlOrm\DataCollector;

use GraphqlOrm\Execution\HydrationObserver;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Query\GraphqlQueryTrace;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

/**
 * @internal
 */
final class GraphqlOrmDataCollector extends DataCollector implements HydrationObserver
{
    /**
     * Queries of a same shape sent this many times are reported, as the sign of a loop (an N+1 problem).
     */
    public const REPEATED_QUERY_THRESHOLD = 3;

    private const LAZY_ORIGIN = 'lazy';

    /**
     * @param float|null           $slowQueryThreshold duration in milliseconds from which a query is reported as slow
     * @param array<string, mixed> $configuration      the options of the bundle shown by the profiler, without secrets
     * @param list<class-string>   $entityClasses      the entities of the mapping directory, described with those the queries read
     */
    public function __construct(
        private readonly QueryRedactor $redactor = new QueryRedactor(),
        private readonly ?float $slowQueryThreshold = null,
        private readonly array $configuration = [],
        private readonly ?GraphqlEntityMetadataFactory $metadataFactory = null,
        private readonly array $entityClasses = [],
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
            'hydration_duration' => $trace->hydrationDuration,
            'started_at' => $trace->startedAt,
            'origin' => $trace->origin,
            'entity_class' => $trace->entityClass,
            'lazy_relation' => $trace->lazyRelation,
            'index_by' => $trace->indexBy,
            'result_cache' => $trace->resultCache,
            'collections' => array_map(
                static fn (string $relation, array $collection) => ['relation' => $relation, ...$collection],
                array_keys($trace->collections),
                array_values($trace->collections),
            ),
            'during_serialization' => $trace->duringSerialization,
            'identifier_property' => $trace->identifierProperty,
            'response_preview' => $trace->responsePreview,
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
     * The mapped entities, those read by the queries of the request first, with their fields as the bundle maps them.
     *
     * @return list<array<string, mixed>>
     */
    public function getEntities(): array
    {
        /** @var list<array<string, mixed>> $entities */
        $entities = $this->data['entities'] ?? [];

        return $entities;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function describeEntities(): array
    {
        if ($this->metadataFactory === null) {
            return [];
        }

        $queriesByClass = array_count_values(array_filter(array_column($this->getQueries(), 'entity_class'), 'is_string'));
        $classes = array_values(array_unique([...array_keys($queriesByClass), ...$this->entityClasses]));
        $entities = [];

        foreach ($classes as $class) {
            $entities[] = ['queries' => $queriesByClass[$class] ?? 0, ...$this->describeEntity($class)];
        }

        usort($entities, fn (array $a, array $b): int => [$b['queries'], $a['class']] <=> [$a['queries'], $b['class']]);

        return $entities;
    }

    /**
     * @return array<string, mixed> the mapping of the entity, or the error making it no entity
     */
    private function describeEntity(string $class): array
    {
        try {
            /** @var class-string $class */
            $metadata = $this->metadataFactory?->getMetadata($class) ?? throw new \LogicException('No metadata factory.');
        } catch (\Throwable $exception) {
            return ['class' => $class, 'error' => $exception->getMessage()];
        }

        return [
            'class' => $class,
            'name' => $metadata->name,
            'repository' => $metadata->repositoryClass,
            'identifier' => $metadata->identifier?->property,
            'fields' => array_map(fn (GraphqlFieldMetadata $field): array => [
                'property' => $field->property,
                'field' => $field->mappedFrom,
                'relation' => $field->relation,
                'collection' => $field->isCollection,
                'fetch' => $field->relation !== null ? $field->fetch->name : null,
            ], array_values($metadata->fields)),
            'error' => null,
        ];
    }

    /**
     * Records an entity the normalizer of the bundle wrote without a query.
     *
     * @param 'identifier'|'partial' $kind a relation not loaded written as its identifier, or a partial entity
     */
    public function entityNormalized(string $class, string $kind): void
    {
        /** @var array{identifier: int, partial: int} $normalized */
        $normalized = $this->data['normalized'] ?? ['identifier' => 0, 'partial' => 0];
        ++$normalized[$kind];
        $this->data['normalized'] = $normalized;
    }

    /**
     * What the serializer wrote without a query, and the lazy loads it sent (an N+1 hidden in a response).
     *
     * @return array{identifiers: int, partial: int, lazy_loads: list<int>} the numbers of the lazy loads, from 1
     */
    public function getSerialization(): array
    {
        /** @var array{identifier: int, partial: int} $normalized */
        $normalized = $this->data['normalized'] ?? ['identifier' => 0, 'partial' => 0];
        $lazyLoads = [];

        foreach ($this->getQueries() as $index => $query) {
            if (($query['during_serialization'] ?? false) === true) {
                $lazyLoads[] = $index + 1;
            }
        }

        return ['identifiers' => $normalized['identifier'], 'partial' => $normalized['partial'], 'lazy_loads' => $lazyLoads];
    }

    public function ghostCreated(string $class, int|string $identifier, ?string $relation): void
    {
        $ghosts = $this->ghosts();
        $ghosts[$class . '#' . $identifier] ??= ['class' => $class, 'relation' => $relation, 'loaded_by' => null];
        $this->data['ghosts'] = $ghosts;
    }

    public function ghostsLoaded(string $class, array $identifiers): void
    {
        $ghosts = $this->ghosts();

        foreach ($identifiers as $identifier) {
            if (isset($ghosts[$class . '#' . $identifier])) {
                $ghosts[$class . '#' . $identifier]['loaded_by'] = $this->getQueryCount();
            }
        }

        $this->data['ghosts'] = $ghosts;
    }

    public function partialEntityHydrated(string $class, array $unselected): void
    {
        $partial = $this->partial();
        $key = $class . '|' . implode(',', $unselected);
        $group = $partial[$key] ?? ['class' => $class, 'unselected' => $unselected, 'hydrated' => 0, 'queries' => [], 'exceptions' => 0, 'missing' => []];
        ++$group['hydrated'];
        // The query hydrating the entity is recorded once hydrated
        $group['queries'] = array_values(array_unique([...$group['queries'], $this->getQueryCount() + 1]));
        $partial[$key] = $group;
        $this->data['partial'] = $partial;
    }

    public function partialEntityRead(string $class, array $missing): void
    {
        $partial = $this->partial();

        foreach ($partial as $key => $group) {
            if ($group['class'] === $class && array_diff($missing, $group['unselected']) === []) {
                ++$group['exceptions'];
                $group['missing'] = array_values(array_unique([...$group['missing'], ...$missing]));
                $partial[$key] = $group;
                break;
            }
        }

        $this->data['partial'] = $partial;
    }

    /**
     * The entities hydrated from a selection of their fields, by class and fields not selected: how many, by which
     * queries, and how many times a property not selected was read (a PartialEntityException).
     *
     * @return list<array{class: string, unselected: list<string>, hydrated: int, queries: list<int>, exceptions: int, missing: list<string>}>
     */
    public function getPartialEntities(): array
    {
        return array_values($this->partial());
    }

    /**
     * @return array<string, array{class: string, unselected: list<string>, hydrated: int, queries: list<int>, exceptions: int, missing: list<string>}>
     */
    private function partial(): array
    {
        /** @var array<string, array{class: string, unselected: list<string>, hydrated: int, queries: list<int>, exceptions: int, missing: list<string>}> $partial */
        $partial = $this->data['partial'] ?? [];

        return $partial;
    }

    /**
     * @return array<string, array{class: string, relation: string|null, loaded_by: int|null}> by class#identifier
     */
    private function ghosts(): array
    {
        /** @var array<string, array{class: string, relation: string|null, loaded_by: int|null}> $ghosts */
        $ghosts = $this->data['ghosts'] ?? [];

        return $ghosts;
    }

    /**
     * The entities left to be loaded on first access (Fetch::Lazy, lazy_loading, references), by relation: how many,
     * how many were loaded and by which queries (from 1), how many were never read and so cost no query.
     *
     * @return list<array{relation: string, class: string, created: int, loaded: int, unread: int, queries: list<int>}>
     */
    public function getLazyLoading(): array
    {
        $groups = [];

        foreach ($this->ghosts() as $ghost) {
            $relation = $ghost['relation'] ?? $ghost['class'];
            $group = $groups[$relation] ?? ['relation' => $relation, 'class' => $ghost['class'], 'created' => 0, 'loaded' => 0, 'unread' => 0, 'queries' => []];
            ++$group['created'];
            $loadedBy = $ghost['loaded_by'];

            if ($loadedBy !== null) {
                ++$group['loaded'];
                $group['queries'] = array_values(array_unique([...$group['queries'], $loadedBy]));
            } else {
                ++$group['unread'];
            }

            $groups[$relation] = $group;
        }

        return array_values($groups);
    }

    /**
     * The URL without the user and password it may hold, e.g. https://user:secret@api.example.com/graphql.
     */
    public static function withoutCredentials(string $url): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@]*@#i', '$1', $url);
    }

    /**
     * Records the outcome of the schema validation, made on the first request of the container.
     *
     * @param list<string>|null $violations null when the schema was not validated
     * @param string|null       $failure    why the schema could not be introspected
     */
    public function setSchemaValidation(string $mode, ?array $violations, ?string $failure): void
    {
        $this->data['schema_validation'] = ['mode' => $mode, 'violations' => $violations, 'failure' => $failure];
    }

    /**
     * Records the search of an autocomplete field. The text searched is masked as the values of the queries, unless
     * redact_query_values is false.
     *
     * @param list<string> $fields the fields searched
     * @param int          $limit  the number of results asked for
     */
    public function autocompleteSearched(string $class, array $fields, string $query, int $results, int $limit): void
    {
        $searches = $this->getAutocompleteSearches();
        $searches[] = [
            'class' => $class,
            'fields' => $fields,
            'query' => ($this->configuration['redact_query_values'] ?? true) !== false ? QueryRedactor::MASK : $query,
            'results' => $results,
            'limit' => $limit,
        ];
        $this->data['autocomplete'] = $searches;
    }

    /**
     * @return list<array{class: string, fields: list<string>, query: string, results: int, limit: int}>
     */
    public function getAutocompleteSearches(): array
    {
        /** @var list<array{class: string, fields: list<string>, query: string, results: int, limit: int}> $searches */
        $searches = $this->data['autocomplete'] ?? [];

        return $searches;
    }

    /**
     * Records a lookup in a cache of introspection results: served by the cache, or introspected.
     *
     * @param float $duration in milliseconds, the introspection included
     */
    public function introspectionCacheLookup(string $name, bool $introspected, float $duration): void
    {
        $caches = $this->getIntrospectionCaches();
        $index = array_search($name, array_column($caches, 'name'), true);
        $cache = $index !== false ? $caches[$index] : ['name' => $name, 'lookups' => 0, 'introspected' => 0, 'duration' => 0.0];
        ++$cache['lookups'];
        $cache['introspected'] += $introspected ? 1 : 0;
        $cache['duration'] += $duration;

        if ($index !== false) {
            $caches[$index] = $cache;
        } else {
            $caches[] = $cache;
        }

        $this->data['introspection_caches'] = $caches;
    }

    /**
     * The caches of introspection results looked up during the request (argument_types, countable_fields,
     * schema_validation): how many lookups, how many introspected the schema, and the time spent.
     *
     * @return list<array{name: string, lookups: int, introspected: int, duration: float}>
     */
    public function getIntrospectionCaches(): array
    {
        /** @var list<array{name: string, lookups: int, introspected: int, duration: float}> $caches */
        $caches = $this->data['introspection_caches'] ?? [];

        return $caches;
    }

    /**
     * @return array{mode: string, violations: list<string>|null, failure: string|null}|null
     */
    public function getSchemaValidation(): ?array
    {
        /** @var array{mode: string, violations: list<string>|null, failure: string|null}|null $validation */
        $validation = $this->data['schema_validation'] ?? null;

        return $validation;
    }

    /**
     * @return array<string, mixed> the options of the bundle, without secrets
     */
    public function getConfiguration(): array
    {
        /** @var array<string, mixed> $configuration */
        $configuration = $this->data['configuration'] ?? [];

        return $configuration;
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
        $configuration = $this->configuration;

        // The endpoint may come from an environment variable, only resolved now.
        if (\is_string($configuration['endpoint'] ?? null)) {
            $configuration['endpoint'] = self::withoutCredentials($configuration['endpoint']);
        }

        $this->data['configuration'] = $configuration;
        // A profile read from the storage only has its data: the constructor is not called again.
        $this->data['slow_query_threshold'] = $this->slowQueryThreshold;
        $this->data['entities'] = $this->describeEntities();
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
        $threshold = $this->data['slow_query_threshold'] ?? $this->slowQueryThreshold ?? null;

        return \is_float($threshold) ? $threshold : null;
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
     * When they are all a find() of the same entity, its class and identifier property tell the findBy() replacing them.
     *
     * @return list<array{shape: string, count: int, queries: list<int>, find_entity: string|null, find_identifier: string|null}>
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
                [$entity, $identifier] = $this->foundOneByOne($queries);
                $repeated[] = ['shape' => (string) $shape, 'count' => \count($queries), 'queries' => $queries, 'find_entity' => $entity, 'find_identifier' => $identifier];
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

    /**
     * @return float the milliseconds spent hydrating the responses, apart from the network
     */
    public function getHydrationDuration(): float
    {
        return array_sum(array_map(fn (array $query): float => \is_float($query['hydration_duration'] ?? null) ? $query['hydration_duration'] : 0.0, $this->getQueries()));
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
     * The entity class and identifier property when these queries are all a find() of the same entity.
     *
     * @param list<int> $numbers the numbers of the queries (from 1)
     *
     * @return array{string|null, string|null}
     */
    private function foundOneByOne(array $numbers): array
    {
        $found = [];

        foreach ($numbers as $number) {
            $query = $this->getQueries()[$number - 1] ?? [];
            $entity = $query['entity_class'] ?? null;
            $identifier = $query['identifier_property'] ?? null;

            if (($query['origin'] ?? null) !== 'find' || !\is_string($entity) || !\is_string($identifier)) {
                return [null, null];
            }

            $found[$entity . '::$' . $identifier] = [$entity, $identifier];
        }

        return \count($found) === 1 ? array_values($found)[0] : [null, null];
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
