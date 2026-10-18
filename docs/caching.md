# Caching

Within a request, the [shared identity map](configuration.md#shared-identity-map) already serves `find()` without a
query. Between requests, two caches keep data of the API, as with Doctrine:

- the **result cache** keeps the response of a query that asks for it;
- the **second-level cache** keeps the entities marked `#[Cache]`, read back by `find()`, the references and the lazy
  loads.

Both store in Symfony cache pools: any adapter works, Redis, APCu or the filesystem.

## Choosing the pools

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            cache.graphql:
                adapter: cache.adapter.redis
                provider: '%env(REDIS_URL)%'

# config/packages/graphql_orm.yaml
graphql_orm:
    result_cache:
        pool: cache.graphql         # default: cache.app; null disables the result cache
    second_level_cache:
        enabled: true               # default: false, #[Cache] is then ignored
        pool: cache.app             # the entities without region
        default_lifetime: 3600      # seconds
        regions:
            reference_data:
                pool: cache.graphql # default: second_level_cache.pool
                lifetime: 86400     # default: second_level_cache.default_lifetime
```

A region names a pool and a lifetime for some entities: the entities choose a region, the configuration of each
environment chooses where it lives.

## Result cache

```php
$tasks = $repository->createQueryBuilder()
    ->where($qb->expr()->eq('done', false))
    ->getQuery()
    ->enableResultCache(lifetime: 300, key: 'tasks.open')
    ->getResult();
```

The response is kept as received, unless it holds errors, and hydrated again when read, through the identity map of the
request. The key defaults to a hash of the query and its variables; give one to evict the
result, or to share it between queries. `lifetime` defaults to the default lifetime of the pool. Each page of a
paginated query is kept apart, so `toIterable()` goes through every page.

`disableResultCache()` sends the query anyway. A query served from the result cache does not keep its entities in the
second-level cache: they are as old as the result.

## Second-level cache

```php
use GraphqlOrm\Mapping\Cache;

#[Entity(name: 'labels')]
#[Cache(region: 'reference_data')]
class Label
{
    // ...
}
```

The second-level cache is disabled by default, as in Doctrine: `#[Cache]` is ignored until
`graphql_orm.second_level_cache.enabled` is true.

An entity marked `#[Cache]` is kept when a query hydrates it **in full** (every mapped field selected): a partial entity
is not. It is then read from the cache by:

- `find()`, after the identity map of the request;
- the references (`getReference()`, `#[GraphqlReference]` of Doctrine entities) and the lazy loads (`Fetch::Lazy`,
  `lazy_loading`): only the identifiers not kept are queried.

Its relations are kept by their identifier, and loaded on first access, as lazy ghosts, whatever `lazy_loading` says: an
entity read from the cache may need a query more than one read from the API. A collection is kept as the identifiers of
its entities.

`#[Cache(lifetime: 600)]` overrides the lifetime of the region. Without region, the entity is kept in
`second_level_cache.pool` for `default_lifetime`; a region the configuration does not declare throws a `LogicException`.
Reading an entity from the cache does not prolong it.

The queries of `findBy()`, `findAll()` or the query builder are still sent: cache their result with the result cache.

### Warming up

`graphqlorm:cache:warmup` loads the entities marked `#[Cache]` from the API, so that the first requests after a
deployment or a clear read them from the cache:

```bash
php bin/console graphqlorm:cache:warmup                  # every entity marked #[Cache], of every manager
php bin/console graphqlorm:cache:warmup Label            # one entity (short name or FQCN)
php bin/console graphqlorm:cache:warmup --manager=crm --batch-size=500
```

```
 -------- --------- ---------------- ---------- ------ --------
  Entity   Manager   Region           Hydrated   Kept   Time
 -------- --------- ---------------- ---------- ------ --------
  Label    main      reference_data   4          4      121 ms
 -------- --------- ---------------- ---------- ------ --------

 [OK] 4 entities kept in the second-level cache.
```

The entities are loaded page by page, `--batch-size` (100 by default) a query, with the memory of a single page; with a
dialect that cannot paginate, they are loaded by one query, which the command tells. "Kept" counts those now in the
cache: an entity hydrated in part, or whose relation has no identifier, is not kept. An entity not marked `#[Cache]`, or
a second-level cache disabled, fails the command.

## Eviction

The bundle does not know when the API changes: the values expire after their lifetime, unless they are evicted. Inject
`GraphqlCache`, or call `$manager->getCache()`:

```php
use GraphqlOrm\Cache\GraphqlCache;

public function __construct(private GraphqlCache $cache) {}

$this->cache->evictEntity(Label::class, 'bug');     // one entity
$this->cache->evictEntityRegion(Label::class);      // every entity of the class
$this->cache->evictQueryResult('tasks.open');       // a result kept under this key (its first page)
$this->cache->containsEntity(Label::class, 'bug');  // false
```

`evictEntityRegion()` works with any pool: the entities of the class get a new key, and the old ones expire. Another
process sees it from its next request. A result kept by the result cache is not evicted with its entities.

## Profiler

The toolbar gives the hits of each cache and the adapter of its pools, e.g. `Result cache 3/4 hits (Redis)`, and the
queries sent to the API. The "Cache" tab of the [profiler](profiler.md) tells where each cache lives (pool,
adapter, lifetime), what it cost (values read and written, time, size), which entities it served and why, the queries
it added (relations of entities read from it, loaded on first access), and the evictions with the line calling them.
It warns when `#[Cache]` is ignored, or when a pool is an `ArrayAdapter`.
