# Configuration

```yaml
# config/packages/graphql_orm.yaml
graphql_orm:
    endpoint: 'http://localhost:4000/graphql'
    max_depth: 1

    # Optional: send root arguments as typed GraphQL variables (see "Variables" below)
    variables:
        enabled: true
        cache_pool: cache.app
        ttl: 3600

    # Optional: mask query values in logs and profiler (default: true)
    redact_query_values: true

    # Optional: id of the HTTP client service (see "HTTP client" below)
    http_client: graphql.client

    # Optional: HTTP client options
    http_client_options:
        verify_host: true
        verify_peer: true

    # Optional: static headers sent with every request
    headers:
        Authorization: 'Bearer my-token'

    # Optional: GraphQL dialect (see dialects.md)
    dialect: GraphqlOrm\Dialect\DefaultDialect

    # Optional: schema validation (see schema-validation.md)
    schema_validation:
        mode: warning # exception | warning | disabled; unset: warning in the dev environment, disabled otherwise

    mapping:
        entity:
            dir: '%kernel.project_dir%/src/GraphQL/Entity'
            namespace: App\GraphQL\Entity
        repository:
            dir: '%kernel.project_dir%/src/GraphQL/Repository'
            namespace: App\GraphQL\Repository
```

## Available options

| Option                            | Default                  | Description                             |
|-----------------------------------|--------------------------|-----------------------------------------|
| `endpoint`                        | *(required)*             | GraphQL API endpoint, at the root or for each manager |
| `managers`                        | `[]`                     | One manager per GraphQL API, each with any option of this table (see [several APIs](multiple-apis.md)) |
| `default_manager`                 | the first manager        | The manager autowired without a name, and of the entities no mapping claims |
| `max_depth`                       | `1`                      | Relation levels loaded by the automatic selection; deeper relations only get their identifier (an explicit `select()` is not limited) |
| `lazy_loading`                    | `false`                  | Load the relations reduced to their identifier on first access (see [lazy loading](#lazy-loading)) |
| `variables.enabled`               | `true`                   | Send root arguments as typed GraphQL variables |
| `variables.cache_pool`            | `cache.app`              | Cache pool storing the introspected argument types |
| `variables.ttl`                   | `3600`                   | Seconds before the schema is introspected again |
| `identity_map`                    | `true`                   | Share the entities between the queries of a request (see [shared identity map](#shared-identity-map)) |
| `slow_query_threshold`            | `null`                   | Milliseconds from which a query is logged as a warning and highlighted in the profiler (see [slow queries](logging.md#slow-queries)) |
| `redact_query_values`             | `true`                   | Mask query values in logs and profiler (see [logging.md](logging.md#masked-values)) |
| `http_client`                     | `null`                   | HTTP client service id; defaults to the autowired `HttpClientInterface` |
| `dialect`                         | `DefaultDialect`         | GraphQL dialect to use                  |
| `headers`                         | `[]`                     | HTTP headers sent with every request    |
| `naming_strategy`                 | `DefaultNamingStrategy`  | Names the fields without `mappedFrom` (see [naming strategy](entity-mapping.md#naming-strategy)) |
| `result_cache.pool`               | `cache.app`              | Cache pool of the [result cache](caching.md#result-cache); `null` disables it |
| `second_level_cache.enabled`      | `false`                  | Keep the entities marked `#[Cache]` (see [second-level cache](caching.md#second-level-cache)); ignored until enabled |
| `second_level_cache.pool`         | `cache.app`              | Cache pool of the entities without region |
| `second_level_cache.default_lifetime` | `3600`               | Seconds an entity is kept, unless its region or `#[Cache]` says otherwise |
| `second_level_cache.regions`      | `[]`                     | Regions by name, each with a `pool` and a `lifetime` (see [choosing the pools](caching.md#choosing-the-pools)) |
| `metadata_cache_pool`             | `cache.system`           | Cache pool for entity metadata outside debug mode (see [metadata cache](#metadata-cache)); `null` disables it |
| `headers_provider`                | `null`                   | Service id computing headers per request (see [dynamic headers](#dynamic-headers)) |
| `http_client_options.verify_host` | `true`                   | TLS host verification                   |
| `http_client_options.verify_peer` | `true`                   | TLS peer verification                   |
| `schema_validation.mode`          | `warning` in the dev environment, `disabled` otherwise | `exception`, `warning`, or `disabled` |
| `mapping.entity.dir`              | `src/GraphQL/Entity`     | Directory where entities are stored     |
| `mapping.entity.namespace`        | `App\GraphQL\Entity`     | Namespace for entities                  |
| `mapping.repository.dir`          | `src/GraphQL/Repository` | Directory where repositories are stored |
| `mapping.repository.namespace`    | `App\GraphQL\Repository` | Namespace for repositories              |

## Lazy loading

Beyond `max_depth`, relations only get their identifier selected. To fetch a given relation lazily whatever the depth,
see [lazy relations](entity-mapping.md#lazy-relations). With `lazy_loading`, they are hydrated as PHP lazy ghosts
instead: reading the identifier costs nothing, and the first access to another property loads the entity with
a query of its own, through its repository `find()`.

```yaml
graphql_orm:
    max_depth: 1
    lazy_loading: true
```

```php
$task = $taskRepository->find(1);   // tasks { id title user { id } }
$task->user->id;                    // no query: the identifier is known
$task->user->name;                  // users(...) { id name ... }: the user is loaded now
```

- With Data API Builder and Hasura, the first access loads every lazy entity of the same class met in the response,
  with one query filtering on their identifiers (`in`, 100 identifiers per query): reading `$task->user->name` for 100
  tasks sends one query, not 100. With the other dialects, which standardize no list filter, each entity is loaded by
  a query of its own (the N+1 problem): raise `max_depth`, or select the fields you need, when you know they will be
  read.
- Each load is shown in the profiler, the logs and the [events](events.md).
- An entity whose complete row comes later in the same response is completed from it, without query.
- An entity deleted in the meantime throws an `EntityNotFoundException` on first access.
- The relations of a lazily loaded entity are lazy as well.

Lazy ghosts are a PHP 8.4 feature: they need no proxy class, and work with `final` entity classes.

## Shared identity map

The queries of a request share their entities, as Doctrine does (`identity_map`, enabled by default):

- An entity is one instance, updated by each query returning it.
- `find()` returns an entity known in full without a query: one met in a previous query, nested relations included.
  An entity known only in part (a `select()` of some fields) or a lazy ghost not loaded yet is still queried.
- The map is reset between requests and between Messenger messages (`kernel.reset`), so that no data outlives them.
  A long-running script calls `GraphqlManager::clear()`.
- `toIterable()` keeps its pages out of it, so that going through a large result set still holds a single page.

With `identity_map: false`, each query has its own identity map: two `find(1)` send two queries and give two
instances.

## HTTP client

By default the bundle uses the application HTTP client, whose timeout is PHP's `default_socket_timeout` (60 s) and
which does not retry. To set a timeout or retries for the GraphQL API only, declare a scoped client and pass its id:

```yaml
# config/packages/framework.yaml
framework:
    http_client:
        scoped_clients:
            graphql.client:
                base_uri: 'https://api.example.com'
                timeout: 5
                max_duration: 30
                retry_failed:
                    max_retries: 2
                    delay: 500
                    multiplier: 2
                    http_codes:
                        0: ['POST']   # network errors and timeouts
                        429: true
                        500: ['POST']
                        502: true
                        503: true
                        504: ['POST']

# config/packages/graphql_orm.yaml
graphql_orm:
    endpoint: 'https://api.example.com/graphql'
    http_client: graphql.client
```

### Retrying temporary errors

GraphQL queries are sent with `POST`, which Symfony does not retry by default on network errors, timeouts, `500` or
`504`: its default strategy only retries those for idempotent methods (`GET`, `PUT`...), and `POST` only on `423`,
`425`, `429`, `502` and `503`. The bundle only sends queries, which read data: listing `POST` for these codes, as
above, makes them retried as well.

- `delay` and `multiplier` space out the attempts (500 ms, then 1 s), and a `Retry-After` header sent by the server is
  honoured.
- An error reported in a `200` response (`{"errors": [...]}`) is not retried: the server answered.
- `max_duration` bounds each attempt: a query may take up to `max_duration × (max_retries + 1)` plus the delays,
  keep it below the time your users can wait.

## Metadata cache

Entity metadata comes from attributes and docblocks. Outside debug mode it is kept in the `metadata_cache_pool`
(`cache.system` by default) and built for every entity of `mapping.entity.dir` by `cache:warmup`, so that no request
pays for it. In debug mode it is only kept for the current request, so mapping changes are seen without clearing the
cache. After a deployment, `cache:clear` (or `cache:warmup`) refreshes it, as for the rest of the Symfony cache.

## Dynamic headers

Static `headers` suit a fixed API key. For a value computed at request time, such as an OAuth access token that
expires, implement `HeadersProviderInterface` and pass its service id. It is called before every request, schema
introspection included, and its headers override the static ones with the same name:

```php
use GraphqlOrm\Client\HeadersProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class OAuthHeadersProvider implements HeadersProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        private TokenClient $tokenClient, // your own client for the OAuth server
    ) {
    }

    public function getHeaders(): array
    {
        $token = $this->cache->get('graphql_api_token', function (ItemInterface $item): string {
            $token = $this->tokenClient->fetchAccessToken();
            $item->expiresAfter($token->expiresIn - 30);

            return $token->value;
        });

        return ['Authorization' => 'Bearer ' . $token];
    }
}
```

```yaml
graphql_orm:
    headers_provider: App\GraphQL\OAuthHeadersProvider
```

Cache the token: the provider runs on every request. Its exceptions are not caught, so a failure to get a token stops
the query.

## Variables

By default (`variables.enabled`), the arguments of the root field are sent as GraphQL variables, their types read from
a cached introspection of the schema; with `enabled: false`, the values are written in the query (escaped):

```graphql
query($filter: TaskFilterInput, $first: Int) {
  tasks(filter: $filter, first: $first) {
    items { id title }
  }
}
```

A variable must be declared with its exact GraphQL type, so the types of the root field arguments are read from the
schema (introspection) and stored in `variables.cache_pool` for `variables.ttl` seconds. A schema change on the API
side is taken into account once the cache expires; clear the pool to apply it immediately.

Values are sent inline, as without the option, when:

- the schema cannot be introspected (many APIs disable introspection in production): a warning is logged and the
  failure is cached for `variables.ttl` seconds, so the API is not introspected on every request;
- an argument is not found on the root field: a warning lists the missing arguments.

Enums are sent as their value and dates as ISO 8601 strings. `getQuery()->getGraphQL()` still shows the query with
inline values.
