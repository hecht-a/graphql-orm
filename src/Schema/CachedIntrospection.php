<?php

declare(strict_types=1);

namespace GraphqlOrm\Schema;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * What an introspection of the schema tells, kept in a cache pool: the schema is not introspected on every request, and
 * the profiler shows whether the cache answered.
 *
 * @internal
 */
final class CachedIntrospection
{
    /**
     * @template T
     *
     * @param string        $profilerName the name of the cache in the profiler
     * @param \Closure(): T $introspect   introspects the schema, its result kept, a failure included
     *
     * @return T
     */
    public static function get(CacheInterface $cache, string $key, int $ttl, ?GraphqlOrmDataCollector $collector, string $profilerName, \Closure $introspect): mixed
    {
        $startedAt = hrtime(true);
        $introspected = false;
        $value = $cache->get($key, static function (ItemInterface $item) use (&$introspected, $ttl, $introspect): mixed {
            $item->expiresAfter($ttl);
            $introspected = true;

            return $introspect();
        });
        $collector?->introspectionCacheLookup($profilerName, $introspected, (hrtime(true) - $startedAt) / 1e6);

        return $value;
    }
}
