<?php

declare(strict_types=1);

namespace GraphqlOrm\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\TraceableAdapter;

/**
 * Tells the profiler where the caches of the bundle live: the id of their pool and its adapter (Redis, APCu, Array...),
 * the TraceableAdapter of the debug mode aside.
 *
 * @internal
 */
final readonly class CachePoolDescriber
{
    private const ADAPTER_SUFFIX = 'Adapter';

    /**
     * @param array<string, array{id: string, pool: CacheItemPoolInterface, lifetime: int|null}> $caches by name: result
     *                                                                                                   cache, default region, or a region
     */
    public function __construct(
        private array $caches,
    ) {
    }

    /**
     * @return list<array{name: string, id: string, adapter: string, lifetime: int|null}>
     */
    public function describe(): array
    {
        $described = [];

        foreach ($this->caches as $name => $cache) {
            $pool = $cache['pool'];

            while ($pool instanceof TraceableAdapter) {
                $pool = $pool->getPool();
            }

            $class = (new \ReflectionClass($pool))->getShortName();
            $adapter = str_ends_with($class, self::ADAPTER_SUFFIX) && $class !== self::ADAPTER_SUFFIX ? substr($class, 0, -\strlen(self::ADAPTER_SUFFIX)) : $class;
            $described[] = ['name' => $name, 'id' => $cache['id'], 'adapter' => $adapter, 'lifetime' => $cache['lifetime']];
        }

        return $described;
    }
}
