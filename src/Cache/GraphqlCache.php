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
    private const RESULT_KEY_PREFIX = 'graphql_orm.result.';

    private const ENTITY_KEY_PREFIX = 'graphql_orm.entity.';

    /** The generation of a class is part of the key of its entities: a new one evicts them all, with any pool. */
    private const GENERATION_KEY_PREFIX = 'graphql_orm.entity_generation.';

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
     */
    public function __construct(
        private readonly ?CacheItemPoolInterface $results = null,
        private readonly ?CacheItemPoolInterface $entities = null,
        private readonly ?int $defaultLifetime = null,
        private readonly array $regions = [],
        private readonly ?GraphqlOrmDataCollector $collector = null,
    ) {
    }

    /**
     * Drops the result kept under a key given to enableResultCache(). A paginated query keeps its other pages apart: only
     * the first page is dropped.
     */
    public function evictQueryResult(string $key): void
    {
        $this->results?->deleteItem(self::resultKey($key));
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
            $region[0]->deleteItem($this->entityKey($class, $identifier, $region[0]));
        }
    }

    /**
     * Drops every entity of a class from the second-level cache, whatever the pool: they are no longer read, and expire.
     *
     * @param class-string $class
     */
    public function evictEntityRegion(string $class): void
    {
        $region = $this->region($class);

        if ($region === null) {
            return;
        }

        $item = $region[0]->getItem(self::GENERATION_KEY_PREFIX . hash('xxh128', $class));
        $generation = \is_int($item->get()) ? $item->get() + 1 : 1;
        $region[0]->save($item->set($generation));
        $this->generations[$class] = $generation;
    }

    /**
     * Forgets the generations read, between two requests: an eviction by another process is then seen.
     */
    public function reset(): void
    {
        $this->generations = [];
    }

    /**
     * The key of the pool under which a result is kept: a hash, the key given by the application holding characters
     * PSR-6 reserves.
     *
     * @internal
     */
    public static function resultKey(string $key): string
    {
        return self::RESULT_KEY_PREFIX . hash('xxh128', $key);
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

        return $this->results->getItem(self::resultKey($key));
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

        $this->results->save($item->set($response)->expiresAfter($lifetime));
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
     * The rows kept for these identifiers, by identifier: those not kept are left out.
     *
     * @param class-string     $class
     * @param list<int|string> $identifiers
     *
     * @return array<int|string, array<string, mixed>>
     *
     * @internal
     */
    public function findEntityRows(string $class, array $identifiers): array
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

        foreach ($region[0]->getItems(array_keys($keys)) as $key => $item) {
            $row = $item instanceof CacheItemInterface && $item->isHit() ? $item->get() : null;

            if (\is_array($row) && \is_string($key) && isset($keys[$key])) {
                $rows[$keys[$key]] = $row;
            }
        }

        $this->collector?->secondLevelCacheLookup($class, $this->attribute($class)?->region, \count($rows), \count($identifiers) - \count($rows));

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
        $region[0]->save($item->set($row)->expiresAfter($region[1]));
        $this->collector?->secondLevelCachePut($class, $this->attribute($class)?->region);
    }

    /**
     * The pool and lifetime of the entities of a class, null when they are not kept.
     *
     * @param class-string $class
     *
     * @return array{CacheItemPoolInterface, int|null}|null
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
            return [$this->entities, $attribute->lifetime ?? $this->defaultLifetime];
        }

        $region = $this->regions[$attribute->region] ?? throw new LogicException(\sprintf('"%s" is kept in the region "%s", which graphql_orm.second_level_cache.regions does not declare.', $class, $attribute->region));

        return [$region['pool'], $attribute->lifetime ?? $region['lifetime'] ?? $this->defaultLifetime];
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
     * @param class-string $class
     */
    private function entityKey(string $class, int|string $identifier, CacheItemPoolInterface $pool): string
    {
        if (!isset($this->generations[$class])) {
            $generation = $pool->getItem(self::GENERATION_KEY_PREFIX . hash('xxh128', $class))->get();
            $this->generations[$class] = \is_int($generation) ? $generation : 0;
        }

        return self::ENTITY_KEY_PREFIX . hash('xxh128', $class . "\0" . $this->generations[$class] . "\0" . $identifier);
    }
}
