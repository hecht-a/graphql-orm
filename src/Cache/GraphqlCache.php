<?php

declare(strict_types=1);

namespace GraphqlOrm\Cache;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Mapping\Cache;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The caches of the bundle holding data of the API between requests, as Doctrine EntityManager::getCache(): the result
 * cache of the queries (GraphqlQuery::enableResultCache()) and the second-level cache of the entities marked #[Cache].
 * Evicting is the only way to drop a value before its lifetime: the bundle does not know when the API changes.
 */
final class GraphqlCache implements ResetInterface
{
    /** The names of the caches in the profiler, besides the regions. */
    public const RESULT_CACHE = 'result cache';
    public const DEFAULT_REGION = 'default region';

    private const RESULT_KEY_PREFIX = 'graphql_orm.result.';

    private const ENTITY_KEY_PREFIX = 'graphql_orm.entity.';

    /** The generation of a class is part of the key of its entities: a new one evicts them all, with any pool. */
    private const GENERATION_KEY_PREFIX = 'graphql_orm.entity_generation.';

    /** The generation of the results of a manager, part of their key in the same way. */
    private const RESULT_GENERATION_KEY_PREFIX = 'graphql_orm.result_generation.';

    /** The generation of the results read during the request. */
    private ?int $resultsGeneration = null;

    /** @var array<class-string, int> the generations read during the request */
    private array $generations = [];

    /** @var array<class-string, Cache|null> */
    private array $attributes = [];

    /**
     * @param CacheItemPoolInterface|null                                             $results         the pool of the result cache
     * @param CacheItemPoolInterface|null                                             $entities        the default pool of the second-level cache,
     *                                                                                                 null when it is disabled
     * @param int|null                                                                $defaultLifetime seconds an entity is kept, null for the
     *                                                                                                 default lifetime of the pool
     * @param array<string, array{pool: CacheItemPoolInterface, lifetime?: int|null}> $regions         by name
     * @param string                                                                  $namespace       the manager of the results, when several
     *                                                                                                 APIs may share a pool
     */
    public function __construct(
        private readonly ?CacheItemPoolInterface $results = null,
        private readonly ?CacheItemPoolInterface $entities = null,
        private readonly ?int $defaultLifetime = null,
        private readonly array $regions = [],
        private readonly ?GraphqlOrmDataCollector $collector = null,
        private readonly string $namespace = '',
    ) {
    }

    /**
     * Drops the result kept under a key given to enableResultCache(). A paginated query keeps its other pages apart: only
     * the first page is dropped.
     */
    public function evictQueryResult(string $key): void
    {
        if ($this->results !== null) {
            $results = $this->results;
            $resultKey = self::resultKey($key, $this->namespace, $this->resultsGeneration());
            $this->measured(self::RESULT_CACHE, 'delete', static fn () => $results->deleteItem($resultKey));
        }

        $this->collector?->cacheEvicted(__FUNCTION__, $key, self::caller());
    }

    /**
     * Drops every result of the manager, whatever the pool, even shared: they are no longer read, and expire. Another
     * process sees it from its next request.
     *
     * @return int the new generation of the results, 0 without result cache
     */
    public function evictQueryResults(): int
    {
        $this->collector?->cacheEvicted(__FUNCTION__, $this->namespace !== '' ? $this->namespace : 'every result', self::caller());

        if ($this->results === null) {
            return 0;
        }

        $results = $this->results;
        $item = $results->getItem($this->resultGenerationKey());
        $generation = (\is_int($item->get()) ? $item->get() : 0) + 1;
        $this->measured(self::RESULT_CACHE, 'write', static fn () => $results->save($item->set($generation)));

        return $this->resultsGeneration = $generation;
    }

    /**
     * How many times every result of the manager was evicted: part of their key.
     *
     * @internal
     */
    public function resultsGeneration(): int
    {
        if ($this->resultsGeneration === null) {
            $generation = $this->results?->getItem($this->resultGenerationKey())->get();
            $this->resultsGeneration = \is_int($generation) ? $generation : 0;
        }

        return $this->resultsGeneration;
    }

    /**
     * Whether the second-level cache holds the entity.
     *
     * @param class-string $class
     */
    public function containsEntity(string $class, int|string $identifier): bool
    {
        $region = $this->region($class);

        return $region !== null && $region[0]->hasItem($this->entityKey($class, $identifier, $region[0]));
    }

    /**
     * Drops an entity from the second-level cache, e.g. after changing it through the API.
     *
     * @param class-string $class
     */
    public function evictEntity(string $class, int|string $identifier): void
    {
        $region = $this->region($class);

        if ($region !== null) {
            $key = $this->entityKey($class, $identifier, $region[0]);
            $this->measured($region[2], 'delete', static fn () => $region[0]->deleteItem($key));
        }

        $this->collector?->cacheEvicted(__FUNCTION__, $class . ' #' . $identifier, self::caller());
    }

    /**
     * Drops every entity of a class from the second-level cache, whatever the pool: they are no longer read, and expire.
     *
     * @param class-string $class
     */
    public function evictEntityRegion(string $class): void
    {
        $region = $this->region($class);
        $this->collector?->cacheEvicted(__FUNCTION__, $class, self::caller());

        if ($region === null) {
            return;
        }

        $item = $region[0]->getItem(self::GENERATION_KEY_PREFIX . hash('xxh128', $class));
        $generation = \is_int($item->get()) ? $item->get() + 1 : 1;
        $this->measured($region[2], 'write', static fn () => $region[0]->save($item->set($generation)));
        $this->generations[$class] = $generation;
    }

    /**
     * Forgets the generations read, between two requests: an eviction by another process is then seen.
     */
    public function reset(): void
    {
        $this->generations = [];
        $this->resultsGeneration = null;
    }

    /**
     * The key of the pool under which a result is kept: a hash, the key given by the application holding characters
     * PSR-6 reserves.
     *
     * @internal
     */
    public static function resultKey(string $key, string $namespace = '', int $generation = 0): string
    {
        $scoped = $namespace === '' ? $key : $namespace . "\0" . $key;

        // The results kept before any eviction keep their key.
        return self::RESULT_KEY_PREFIX . hash('xxh128', $generation === 0 ? $scoped : $generation . "\0" . $scoped);
    }

    private function resultGenerationKey(): string
    {
        return self::RESULT_GENERATION_KEY_PREFIX . hash('xxh128', $this->namespace);
    }

    /**
     * The item of a result in the pool, keyed by the key given by the application and the page, or by the query and its
     * variables.
     *
     * @param array<string, mixed> $variables
     *
     * @throws LogicException when no pool is configured
     *
     * @internal
     */
    public function resultItem(string $graphql, array $variables, ?string $key, ?string $cursor): CacheItemInterface
    {
        if ($this->results === null) {
            throw new LogicException('enableResultCache() needs a cache pool: set graphql_orm.result_cache.pool.');
        }

        $key = $key !== null
            ? ($cursor !== null ? $key . "\0" . $cursor : $key)
            : $graphql . "\0" . json_encode($variables, \JSON_THROW_ON_ERROR);

        $results = $this->results;
        $key = self::resultKey($key, $this->namespace, $this->resultsGeneration());

        return $this->measured(self::RESULT_CACHE, 'read', static fn () => $results->getItem($key));
    }

    /**
     * Keeps a response, unless it holds errors.
     *
     * @param array<string, mixed> $response
     * @param int|null             $lifetime in seconds, null for the default lifetime of the pool
     *
     * @internal
     */
    public function saveResult(CacheItemInterface $item, array $response, ?int $lifetime): void
    {
        if (($response['errors'] ?? []) !== [] || $this->results === null) {
            return;
        }

        $results = $this->results;
        $this->measured(self::RESULT_CACHE, 'write', static fn () => $results->save($item->set($response)->expiresAfter($lifetime)), $response);
    }

    /**
     * Whether the entities of the class are kept in the second-level cache.
     *
     * @param class-string $class
     *
     * @internal
     */
    public function caches(string $class): bool
    {
        return $this->region($class) !== null;
    }

    /**
     * The region the entities of the class are kept in, null when they are not kept.
     *
     * @param class-string $class
     *
     * @internal
     */
    public function regionName(string $class): ?string
    {
        return $this->region($class)[2] ?? null;
    }

    /**
     * Seconds the entities of the class are kept, null for the default lifetime of the pool or when they are not kept.
     *
     * @param class-string $class
     *
     * @internal
     */
    public function entityLifetime(string $class): ?int
    {
        return $this->region($class)[1] ?? null;
    }

    /**
     * How many times every entity of the class was evicted: part of their key.
     *
     * @param class-string $class
     *
     * @internal
     */
    public function entityGeneration(string $class): int
    {
        $region = $this->region($class);

        return $region !== null ? $this->generation($class, $region[0]) : 0;
    }

    /**
     * Whether the second-level cache is enabled: graphql_orm.second_level_cache.enabled.
     *
     * @internal
     */
    public function keepsEntities(): bool
    {
        return $this->entities !== null;
    }

    /**
     * The rows kept for these identifiers, by identifier: those not kept are left out.
     *
     * @param class-string     $class
     * @param list<int|string> $identifiers
     * @param string|null      $trigger     what looks them up, told to the profiler (find, a lazy load)
     *
     * @return array<int|string, array<string, mixed>>
     *
     * @internal
     */
    public function findEntityRows(string $class, array $identifiers, ?string $trigger = null): array
    {
        $region = $this->region($class);

        if ($region === null || $identifiers === []) {
            return [];
        }

        $keys = [];

        foreach ($identifiers as $identifier) {
            $keys[$this->entityKey($class, $identifier, $region[0])] = $identifier;
        }

        $rows = [];

        $items = $this->measured($region[2], 'read', static fn () => iterator_to_array($region[0]->getItems(array_keys($keys))), count: \count($keys));

        foreach ($items as $key => $item) {
            $row = $item instanceof CacheItemInterface && $item->isHit() ? $item->get() : null;

            if (\is_array($row) && \is_string($key) && isset($keys[$key])) {
                $rows[$keys[$key]] = $row;
            }
        }

        foreach ($identifiers as $identifier) {
            $this->collector?->secondLevelCacheEntry($class, $this->attribute($class)?->region, $identifier, \array_key_exists($identifier, $rows) ? 'hit' : 'miss', $trigger);
        }

        /** @var array<int|string, array<string, mixed>> $rows the rows kept by the bundle, keyed by GraphQL field */
        return $rows;
    }

    /**
     * Keeps the row of an entity hydrated in full, its relations reduced to their identifier.
     *
     * @param class-string         $class
     * @param array<string, mixed> $row
     *
     * @internal
     */
    public function putEntityRow(string $class, int|string $identifier, array $row): void
    {
        $region = $this->region($class);

        if ($region === null) {
            return;
        }

        $item = $region[0]->getItem($this->entityKey($class, $identifier, $region[0]));
        $this->measured($region[2], 'write', static fn () => $region[0]->save($item->set($row)->expiresAfter($region[1])), $row);
        // The query being hydrated, named by the profiler
        $this->collector?->secondLevelCacheEntry($class, $this->attribute($class)?->region, $identifier, 'kept', null);
    }

    /**
     * The pool, lifetime and name of the region of the entities of a class, null when they are not kept.
     *
     * @param class-string $class
     *
     * @return array{CacheItemPoolInterface, int|null, string}|null
     *
     * @throws LogicException when the region of the class is not configured
     */
    private function region(string $class): ?array
    {
        if ($this->entities === null) {
            return null;
        }

        $attribute = $this->attribute($class);

        if ($attribute === null) {
            return null;
        }

        if ($attribute->region === null) {
            return [$this->entities, $attribute->lifetime ?? $this->defaultLifetime, self::DEFAULT_REGION];
        }

        $region = $this->regions[$attribute->region] ?? throw new LogicException(\sprintf('"%s" is kept in the region "%s", which graphql_orm.second_level_cache.regions does not declare.', $class, $attribute->region));

        return [$region['pool'], $attribute->lifetime ?? $region['lifetime'] ?? $this->defaultLifetime, $attribute->region];
    }

    /**
     * @param class-string $class
     */
    private function attribute(string $class): ?Cache
    {
        if (!\array_key_exists($class, $this->attributes)) {
            $this->attributes[$class] = class_exists($class)
                ? ((new \ReflectionClass($class))->getAttributes(Cache::class)[0] ?? null)?->newInstance()
                : null;
        }

        return $this->attributes[$class];
    }

    /**
     * Runs an operation on a pool, its time and the size of what it writes told to the profiler.
     *
     * @template R
     *
     * @param 'read'|'write'|'delete' $operation
     * @param \Closure(): R           $run
     * @param array<mixed>|null       $written   the value written, measured as serialized by PHP
     *
     * @return R
     */
    private function measured(string $cache, string $operation, \Closure $run, ?array $written = null, int $count = 1): mixed
    {
        if ($this->collector === null) {
            return $run();
        }

        $startedAt = hrtime(true);
        $result = $run();
        $this->collector->cacheOperation($cache, $operation, $count, (hrtime(true) - $startedAt) / 1e6, $written !== null ? \strlen(serialize($written)) : 0);

        return $result;
    }

    /**
     * The file and line of the application calling an eviction.
     */
    private static function caller(): ?string
    {
        $frame = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];

        return isset($frame['file']) ? $frame['file'] . (isset($frame['line']) ? ':' . $frame['line'] : '') : null;
    }

    /**
     * @param class-string $class
     */
    private function entityKey(string $class, int|string $identifier, CacheItemPoolInterface $pool): string
    {
        return self::ENTITY_KEY_PREFIX . hash('xxh128', $class . "\0" . $this->generation($class, $pool) . "\0" . $identifier);
    }

    /**
     * The generation of the entities of a class, read once per request.
     *
     * @param class-string $class
     */
    private function generation(string $class, CacheItemPoolInterface $pool): int
    {
        if (!isset($this->generations[$class])) {
            $generation = $pool->getItem(self::GENERATION_KEY_PREFIX . hash('xxh128', $class))->get();
            $this->generations[$class] = \is_int($generation) ? $generation : 0;
        }

        return $this->generations[$class];
    }
}
