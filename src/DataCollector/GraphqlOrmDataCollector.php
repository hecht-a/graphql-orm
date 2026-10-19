<?php

declare(strict_types=1);

namespace GraphqlOrm\DataCollector;

use GraphqlOrm\Cache\CachePoolDescriber;
use GraphqlOrm\Cache\GraphqlCache;
use GraphqlOrm\Converter\TypeConverterRegistry;
use GraphqlOrm\Execution\HydrationObserver;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Mapping\Cache;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Query\GraphqlQueryTrace;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;

/**
 * @internal
 */
final class GraphqlOrmDataCollector extends DataCollector implements HydrationObserver, LateDataCollectorInterface
{
    /**
     * Queries of a same shape sent this many times are reported, as the sign of a loop (an N+1 problem), unless
     * profiler.repeated_queries says otherwise.
     */
    public const REPEATED_QUERY_THRESHOLD = 3;

    /** The entities of the second-level cache detailed by a profile, which a page reading thousands would bloat. */
    private const MAX_CACHE_ENTRIES = 500;

    /**
     * The queries kept in detail by a profile: beyond, a query keeps what the metrics and the alerts need, not its text,
     * variables, AST nor response, which a page sending thousands of queries would bloat the profile with.
     */
    private const MAX_DETAILED_QUERIES = 500;

    /** The adapter of CachePoolDescriber keeping its values in the memory of the process. */
    private const ARRAY_ADAPTER = 'Array';

    /**
     * @param float|null           $slowQueryThreshold duration in milliseconds from which a query is reported as slow
     * @param array<string, mixed> $configuration      the options of the bundle shown by the profiler, without secrets
     * @param list<class-string>   $entityClasses      the entities of the mapping directory, described with those the queries read
     * @param int|null             $maxQueries         the queries of a request beyond which the toolbar turns yellow
     * @param float|null           $maxQueryTime       the milliseconds in GraphQL beyond which the toolbar turns yellow
     */
    public function __construct(
        private readonly QueryRedactor $redactor = new QueryRedactor(),
        private readonly ?float $slowQueryThreshold = null,
        private readonly array $configuration = [],
        private readonly ?GraphqlEntityMetadataFactory $metadataFactory = null,
        private readonly array $entityClasses = [],
        private readonly ?CachePoolDescriber $cachePools = null,
        private readonly ?TypeConverterRegistry $typeConverters = null,
        // Tells the sub-requests (fragments, forwards) apart from the main request, which they share the collector with
        private readonly ?RequestStack $requestStack = null,
        private readonly ?int $maxQueries = null,
        private readonly ?float $maxQueryTime = null,
        private readonly int $repeatedQueryThreshold = self::REPEATED_QUERY_THRESHOLD,
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
            'from_cached_entity' => $trace->fromCachedEntity,
            'index_by' => $trace->indexBy,
            'hydrated_as' => $trace->hydratedAs,
            'with' => $trace->with,
            // Sent once the response was collected: by a kernel.terminate listener, or a streamed response
            'after_response' => ($this->data['collected'] ?? false) === true,
            'sub_request' => $this->subRequestPath(),
            'detailed' => true,
            'redacted' => $trace->redacted,
            'result_cache' => $trace->resultCache,
            'persisted_query' => $trace->persistedQuery,
            'manager' => $trace->manager,
            'result_cache_key' => $trace->resultCacheKey,
            'result_cache_lifetime' => $trace->resultCacheLifetime,
            'collections' => array_map(
                static fn (string $relation, array $collection) => ['relation' => $relation, ...$collection],
                array_keys($trace->collections),
                array_values($trace->collections),
            ),
            'during_serialization' => $trace->duringSerialization,
            'identifier_property' => $trace->identifierProperty,
            'response_preview' => $trace->responsePreview,
            // The threshold of the manager of the query, each API having its own
            'slow' => ($trace->slowQueryThreshold ?? $this->slowQueryThreshold) !== null && $trace->duration >= ($trace->slowQueryThreshold ?? $this->slowQueryThreshold),
        ];
        $this->data['queries'] = $this->withoutDetailBeyondTheLimit($queries);
    }

    public function getDetailedQueryLimit(): int
    {
        return self::MAX_DETAILED_QUERIES;
    }

    /**
     * @param array<int, array<string, mixed>> $queries
     *
     * @return array<int, array<string, mixed>>
     */
    private function withoutDetailBeyondTheLimit(array $queries): array
    {
        $last = \count($queries) - 1;

        if ($last >= self::MAX_DETAILED_QUERIES) {
            $queries[$last] = ['graphql' => '', 'ast' => null, 'variables' => [], 'curl' => null, 'response_preview' => null, 'detailed' => false] + $queries[$last];
        }

        return $queries;
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
                ...$this->describeConverter($field),
                // The value object and the response fields holding it
                'embedded' => $field->embedded !== null ? [
                    'class' => $field->embedded->class,
                    'fields' => array_map(
                        static fn (GraphqlFieldMetadata $embeddedField): string => $field->embedded->prefix !== null
                            ? $field->embedded->graphqlName($embeddedField)
                            : $field->mappedFrom . '.' . $embeddedField->mappedFrom,
                        $field->embedded->fields,
                    ),
                ] : null,
            ], array_values($metadata->fields)),
            'error' => null,
        ];
    }

    /**
     * The type converter of a field: the one its #[GraphqlOrm\Field] names, or the one registered for the class of its
     * property.
     *
     * @return array{converter: string|null, converter_source: 'field'|'type'|null}
     */
    private function describeConverter(GraphqlFieldMetadata $field): array
    {
        if ($field->converter !== null) {
            return ['converter' => $field->converter, 'converter_source' => 'field'];
        }

        $converter = $field->relation === null && $field->embedded === null ? $this->typeConverters?->converterFor(null, $field->valueClass) : null;

        return $converter !== null
            ? ['converter' => $converter::class, 'converter_source' => 'type']
            : ['converter' => null, 'converter_source' => null];
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
     * Records an entity read from the second-level cache (hit), not found there (miss), or kept in it.
     *
     * @param string|null         $region  null for the default region
     * @param 'hit'|'miss'|'kept' $outcome
     * @param string|null         $trigger what looked it up (find, a lazy load); the query being hydrated when null
     */
    public function secondLevelCacheEntry(string $class, ?string $region, int|string $identifier, string $outcome, ?string $trigger): void
    {
        $this->countSecondLevelCache($class, $region, [match ($outcome) {
            'hit' => 'hits',
            'miss' => 'misses',
            'kept' => 'puts',
        } => 1]);

        /** @var array<string, array{class: string, region: string|null, identifier: string, outcome: string, trigger: string, count: int}> $entries */
        $entries = $this->data['second_level_cache_entries'] ?? [];
        $trigger ??= 'query #' . ($this->getQueryCount() + 1);
        $key = $class . "\0" . $identifier . "\0" . $outcome . "\0" . $trigger;

        if (isset($entries[$key])) {
            ++$entries[$key]['count'];
        } elseif (\count($entries) < self::MAX_CACHE_ENTRIES) {
            $entries[$key] = ['class' => $class, 'region' => $region, 'identifier' => (string) $identifier, 'outcome' => $outcome, 'trigger' => $trigger, 'count' => 1];
        }

        $this->data['second_level_cache_entries'] = $entries;
    }

    /**
     * The entities read from the second-level cache, not found there or kept, with what looked them up, in order; the
     * first MAX_CACHE_ENTRIES only.
     *
     * @return list<array{class: string, region: string|null, identifier: string, outcome: string, trigger: string, count: int}>
     */
    public function getSecondLevelCacheEntries(): array
    {
        /** @var array<string, array{class: string, region: string|null, identifier: string, outcome: string, trigger: string, count: int}> $entries */
        $entries = $this->data['second_level_cache_entries'] ?? [];

        return array_values($entries);
    }

    /**
     * Records an operation on the pool of a cache of the bundle.
     *
     * @param string                  $cache     result cache, default region, or the name of a region
     * @param 'read'|'write'|'delete' $operation
     * @param int                     $count     the values read, written or deleted
     * @param float                   $duration  in milliseconds
     * @param int                     $bytes     the size of the values written, as serialized by PHP
     */
    public function cacheOperation(string $cache, string $operation, int $count, float $duration, int $bytes): void
    {
        /** @var array<string, array{name: string, reads: int, writes: int, deletes: int, duration: float, bytes_written: int}> $operations */
        $operations = $this->data['cache_operations'] ?? [];
        $entry = $operations[$cache] ?? ['name' => $cache, 'reads' => 0, 'writes' => 0, 'deletes' => 0, 'duration' => 0.0, 'bytes_written' => 0];
        $entry[$operation . 's'] += $count;
        $entry['duration'] += $duration;
        $entry['bytes_written'] += $bytes;
        $operations[$cache] = $entry;
        $this->data['cache_operations'] = $operations;
    }

    /**
     * The operations on the pools of the caches, by cache: values read, written and deleted, their time and the size
     * written.
     *
     * @return list<array{name: string, reads: int, writes: int, deletes: int, duration: float, bytes_written: int}>
     */
    public function getCacheOperations(): array
    {
        /** @var array<string, array{name: string, reads: int, writes: int, deletes: int, duration: float, bytes_written: int}> $operations */
        $operations = $this->data['cache_operations'] ?? [];

        return array_values($operations);
    }

    /**
     * Where the caches of the bundle live: the id and the adapter of their pool, and their lifetime.
     *
     * @return list<array{name: string, id: string, adapter: string, lifetime: int|null}>
     */
    public function getCachePools(): array
    {
        /** @var list<array{name: string, id: string, adapter: string, lifetime: int|null}> $pools */
        $pools = $this->data['cache_pools'] ?? [];

        return $pools;
    }

    /**
     * What is wrong with the configuration of the caches, e.g. #[GraphqlOrm\Cache] ignored.
     *
     * @return list<string>
     */
    public function getCacheWarnings(): array
    {
        /** @var list<string> $warnings */
        $warnings = $this->data['cache_warnings'] ?? [];

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function cacheWarnings(): array
    {
        $warnings = [];
        $secondLevel = $this->getConfiguration()['second_level_cache'] ?? null;

        if (\is_array($secondLevel) && ($secondLevel['enabled'] ?? false) !== true) {
            $classes = array_unique([...$this->entityClasses, ...array_filter(array_column($this->getQueries(), 'entity_class'), \is_string(...))]);

            foreach ($classes as $class) {
                if (class_exists($class) && (new \ReflectionClass($class))->getAttributes(Cache::class) !== []) {
                    $warnings[] = \sprintf('#[GraphqlOrm\Cache] is ignored on %s: graphql_orm.second_level_cache.enabled is false.', (new \ReflectionClass($class))->getShortName());
                }
            }
        }

        foreach ($this->cachePools?->describe() ?? [] as $pool) {
            if ($pool['adapter'] === self::ARRAY_ADAPTER) {
                $cache = match ($pool['name']) {
                    GraphqlCache::RESULT_CACHE => 'The result cache',
                    GraphqlCache::DEFAULT_REGION => 'The default region',
                    default => 'The region ' . $pool['name'],
                };
                $warnings[] = \sprintf('%s is kept in %s, an ArrayAdapter: its values do not outlive the process.', $cache, $pool['id']);
            }
        }

        return $warnings;
    }

    /**
     * Records an eviction from the caches of the bundle.
     *
     * @param string      $call   the method of GraphqlCache, e.g. evictEntity
     * @param string      $target the entity, the class or the key evicted
     * @param string|null $caller the file and line calling it
     */
    public function cacheEvicted(string $call, string $target, ?string $caller): void
    {
        /** @var list<array{call: string, target: string, caller: string|null}> $evictions */
        $evictions = $this->data['cache_evictions'] ?? [];
        $evictions[] = ['call' => $call, 'target' => $target, 'caller' => $caller];
        $this->data['cache_evictions'] = $evictions;
    }

    /**
     * @return list<array{call: string, target: string, caller: string|null}>
     */
    public function getCacheEvictions(): array
    {
        /** @var list<array{call: string, target: string, caller: string|null}> $evictions */
        $evictions = $this->data['cache_evictions'] ?? [];

        return $evictions;
    }

    /**
     * The second-level cache during the request, by entity: its region, the entities read from it, those not found
     * there, and those kept.
     *
     * @return list<array{class: string, region: string|null, hits: int, misses: int, puts: int}>
     */
    public function getSecondLevelCache(): array
    {
        /** @var array<string, array{class: string, region: string|null, hits: int, misses: int, puts: int}> $classes */
        $classes = $this->data['second_level_cache'] ?? [];

        return array_values($classes);
    }

    /**
     * @param array{hits?: int, misses?: int, puts?: int} $counts
     */
    private function countSecondLevelCache(string $class, ?string $region, array $counts): void
    {
        /** @var array<string, array{class: string, region: string|null, hits: int, misses: int, puts: int}> $classes */
        $classes = $this->data['second_level_cache'] ?? [];
        $entry = $classes[$class] ?? ['class' => $class, 'region' => $region, 'hits' => 0, 'misses' => 0, 'puts' => 0];

        foreach ($counts as $name => $count) {
            $entry[$name] += $count;
        }

        $classes[$class] = $entry;
        $this->data['second_level_cache'] = $classes;
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
        // Before collect(), e.g. while the request runs: the options given to the collector
        /** @var array<string, mixed> $configuration */
        $configuration = $this->data['configuration'] ?? $this->configuration;

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
     * The query as a cURL command, to replay it from a terminal. Headers are not recorded: authentication is to be
     * added.
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
        $this->data['thresholds'] = ['max_queries' => $this->maxQueries, 'max_query_time' => $this->maxQueryTime, 'repeated_queries' => $this->repeatedQueryThreshold];
        $this->data['entities'] = $this->describeEntities();
        $this->data['cache_pools'] = $this->cachePools?->describe() ?? [];
        $this->data['cache_warnings'] = $this->cacheWarnings();
        $startedAt = $request->server->get('REQUEST_TIME_FLOAT');
        $this->data['request_started_at'] = \is_float($startedAt) || \is_int($startedAt) ? (float) $startedAt : null;
        // A sub-request is collected as well, before the end of the main request.
        if ($this->requestStack === null || $this->requestStack->getMainRequest() === null || $this->requestStack->getMainRequest() === $request) {
            $this->data['collected'] = true;
        }
    }

    /**
     * The path of the sub-request (a fragment, a forward) sending a query now, null during the main request.
     */
    private function subRequestPath(): ?string
    {
        $current = $this->requestStack?->getCurrentRequest();

        return $current !== null && $current !== $this->requestStack->getMainRequest() ? $current->getPathInfo() : null;
    }

    /**
     * Once the response is sent, before the profile is saved: the queries sent since (a kernel.terminate listener, a
     * streamed response) are kept already, what is told of the entities they read is not.
     */
    public function lateCollect(): void
    {
        if (($this->data['collected'] ?? false) !== true) {
            return;
        }

        $this->data['entities'] = $this->describeEntities();
        $this->data['cache_warnings'] = $this->cacheWarnings();
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

    public function getMaxQueries(): ?int
    {
        $thresholds = $this->data['thresholds'] ?? null;
        $max = \is_array($thresholds) ? $thresholds['max_queries'] ?? null : $this->maxQueries;

        return \is_int($max) ? $max : null;
    }

    public function getMaxQueryTime(): ?float
    {
        $thresholds = $this->data['thresholds'] ?? null;
        $max = \is_array($thresholds) ? $thresholds['max_query_time'] ?? null : $this->maxQueryTime;

        return \is_float($max) ? $max : null;
    }

    public function getRepeatedQueryThreshold(): int
    {
        $thresholds = $this->data['thresholds'] ?? null;
        $threshold = \is_array($thresholds) ? $thresholds['repeated_queries'] ?? null : $this->repeatedQueryThreshold;

        return \is_int($threshold) ? $threshold : self::REPEATED_QUERY_THRESHOLD;
    }

    /** More queries than profiler.max_queries: the toolbar turns yellow. */
    public function isOverMaxQueries(): bool
    {
        $max = $this->getMaxQueries();

        return $max !== null && $this->getQueryCount() > $max;
    }

    /** More time waiting for GraphQL than profiler.max_query_time: the toolbar turns yellow. */
    public function isOverMaxQueryTime(): bool
    {
        $max = $this->getMaxQueryTime();

        return $max !== null && $this->getWaitedDuration() > $max;
    }

    public function getQueryCount(): int
    {
        return \count($this->getQueries());
    }

    public function getTotalDuration(): float
    {
        return $this->timeline()->totalDuration();
    }

    /**
     * The milliseconds the request waited for the API: the queries sent together counted once, unlike
     * getTotalDuration().
     */
    public function getWaitedDuration(): float
    {
        return $this->timeline()->waitedDuration();
    }

    /**
     * @return list<array{shape: string, count: int, queries: list<int>, find_entity: string|null, find_identifier: string|null}>
     */
    public function getRepeatedQueries(): array
    {
        return $this->findings()->repeatedQueries();
    }

    /**
     * @return list<float> when each query started, in milliseconds since the request started
     */
    public function getQueryOffsets(): array
    {
        return $this->timeline()->offsets();
    }

    public function getTimelineSpan(): float
    {
        return $this->timeline()->span();
    }

    /**
     * @return list<float>
     */
    public function getTimelineTicks(): array
    {
        return $this->timeline()->ticks();
    }

    /**
     * The queries of different shapes, their values aside, as the "different statements" of Doctrine.
     */
    public function getDistinctQueryCount(): int
    {
        return \count(array_unique(array_map(static fn (array $query) => \is_string($query['shape'] ?? null) ? $query['shape'] : '', $this->getQueries())));
    }

    /**
     * The result cache during the request: the queries answered by it, among those asking for it; null when disabled.
     *
     * @return array{hits: int, lookups: int}|null
     */
    public function getResultCacheUsage(): ?array
    {
        $resultCache = $this->getConfiguration()['result_cache'] ?? null;

        if (\is_array($resultCache) && ($resultCache['pool'] ?? null) === null) {
            return null;
        }

        $lookups = array_filter($this->getQueries(), static fn (array $query) => ($query['result_cache'] ?? null) !== null);

        return ['hits' => $this->getResultCacheHitCount(), 'lookups' => \count($lookups)];
    }

    /**
     * The second-level cache during the request: the entities read from it, among those looked up; null when disabled.
     *
     * @return array{hits: int, lookups: int}|null
     */
    public function getSecondLevelCacheUsage(): ?array
    {
        $secondLevel = $this->getConfiguration()['second_level_cache'] ?? null;

        if (\is_array($secondLevel) && ($secondLevel['enabled'] ?? false) !== true) {
            return null;
        }

        $hits = array_sum(array_column($this->getSecondLevelCache(), 'hits'));

        return ['hits' => $hits, 'lookups' => $hits + array_sum(array_column($this->getSecondLevelCache(), 'misses'))];
    }

    /**
     * The queries answered by the result cache, without being sent.
     */
    public function getResultCacheHitCount(): int
    {
        return \count(array_filter($this->getQueries(), static fn (array $query) => ($query['result_cache'] ?? null) === 'hit'));
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
     * @return list<array{relation: string, count: int, queries: list<int>, duration: float, callers: list<string>}>
     */
    public function getLazyLoadsByRelation(): array
    {
        return $this->findings()->lazyLoadsByRelation();
    }

    /**
     * @return list<array{caller: string|null, count: int, duration: float, origins: list<string>, entities: list<string>, queries: list<int>}>
     */
    public function getQueriesByCaller(): array
    {
        return $this->findings()->queriesByCaller();
    }

    /**
     * The profile of the queries as JSON data, for a ticket or to compare two versions of a page: the metrics, the
     * thresholds, each query detailed by the profile (its values masked unless redact_query_values is false, without
     * its AST nor its response) and the findings.
     *
     * @return array{
     *   format: string,
     *   version: int,
     *   summary: array<string, int|float>,
     *   thresholds: array<string, int|float|null>,
     *   queries: list<array<string, mixed>>,
     *   findings: array<string, list<array<string, mixed>>>
     * }
     */
    public function getExport(): array
    {
        $offsets = $this->getQueryOffsets();
        $queries = [];

        foreach (\array_slice($this->getQueries(), 0, self::MAX_DETAILED_QUERIES) as $index => $query) {
            $queries[] = [
                'number' => $index + 1,
                'manager' => $query['manager'] ?? null,
                'origin' => $query['origin'] ?? null,
                'entity' => $query['entity_class'] ?? null,
                'root_field' => $query['root_field'] ?? null,
                'graphql' => $query['graphql'] ?? null,
                'variables' => $query['variables'] ?? [],
                'values_masked' => $query['redacted'] ?? true,
                'caller' => ProfiledQuery::caller($query),
                'started_ms' => $offsets[$index] ?? 0.0,
                'duration_ms' => $query['duration'] ?? 0,
                'hydration_ms' => $query['hydration_duration'] ?? 0.0,
                'response_bytes' => $query['response_size'] ?? 0,
                'entities_hydrated' => $query['hydrated_entities'] ?? 0,
                'result_cache' => $query['result_cache'] ?? null,
                'slow' => $query['slow'] ?? false,
                'errors' => $query['errors'] ?? [],
                'sub_request' => $query['sub_request'] ?? null,
                'after_response' => $query['after_response'] ?? false,
            ];
        }

        return [
            'format' => 'graphql-orm-profile',
            'version' => 1,
            'summary' => [
                'queries' => $this->getQueryCount(),
                'sent' => $this->getQueryCount() - $this->getResultCacheHitCount(),
                'distinct' => $this->getDistinctQueryCount(),
                'waited_ms' => $this->getWaitedDuration(),
                'summed_ms' => $this->getTotalDuration(),
                'hydration_ms' => $this->getHydrationDuration(),
                'errors' => $this->getErrorCount(),
                'slow' => $this->getSlowQueryCount(),
                'entities_hydrated' => $this->getHydratedEntityCount(),
                'response_bytes' => $this->getResponseSize(),
            ],
            'thresholds' => [
                'slow_query_ms' => $this->getSlowQueryThreshold(),
                'max_queries' => $this->getMaxQueries(),
                'max_query_time_ms' => $this->getMaxQueryTime(),
                'repeated_queries' => $this->getRepeatedQueryThreshold(),
            ],
            'queries' => $queries,
            'findings' => [
                'lazy_loads' => array_map(
                    static fn (array $load): array => ['relation' => $load['relation'], 'count' => $load['count'], 'queries' => $load['queries'], 'duration_ms' => $load['duration'], 'callers' => $load['callers']],
                    $this->getLazyLoadsByRelation(),
                ),
                'repeated_queries' => array_map(
                    static fn (array $repeated): array => ['shape' => $repeated['shape'], 'count' => $repeated['count'], 'queries' => $repeated['queries']],
                    $this->getRepeatedQueries(),
                ),
                'cache_candidates' => $this->getCacheCandidates(),
            ],
        ];
    }

    /**
     * @return list<array{class: string, count: int, queries: list<int>}>
     */
    public function getCacheCandidates(): array
    {
        return $this->findings()->cacheCandidates();
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

    private function timeline(): ProfileTimeline
    {
        $startedAt = $this->data['request_started_at'] ?? null;

        return new ProfileTimeline($this->getQueries(), \is_float($startedAt) ? $startedAt : null);
    }

    private function findings(): ProfileFindings
    {
        return new ProfileFindings($this->getQueries(), $this->getRepeatedQueryThreshold());
    }
}
