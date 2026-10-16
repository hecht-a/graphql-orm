<?php

declare(strict_types=1);

namespace GraphqlOrm\Mapping;

/**
 * Keeps the entities of the class between requests once hydrated in full, as the second-level cache of Doctrine:
 * find(), the references and the lazy loads read them from there. Their relations are kept by their identifier, and
 * loaded on first access.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Cache
{
    /**
     * @param string|null $region   a region of graphql_orm.second_level_cache.regions, giving the pool and the lifetime;
     *                              the pool and lifetime of second_level_cache when null
     * @param int|null    $lifetime seconds an entity is kept, overriding the lifetime of its region
     */
    public function __construct(
        public ?string $region = null,
        public ?int $lifetime = null,
    ) {
    }
}
