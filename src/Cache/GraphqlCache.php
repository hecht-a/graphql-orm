<?php

declare(strict_types=1);

namespace GraphqlOrm\Cache;

use GraphqlOrm\Exception\LogicException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The caches of the bundle holding data of the API between requests, as Doctrine EntityManager::getCache(): the result
 * cache of the queries (GraphqlQuery::enableResultCache()). Evicting is the only way to drop a value before its lifetime:
 * the bundle does not know when the API changes.
 */
final readonly class GraphqlCache
{
    private const RESULT_KEY_PREFIX = 'graphql_orm.result.';

    public function __construct(
        private ?CacheItemPoolInterface $results = null,
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
}
