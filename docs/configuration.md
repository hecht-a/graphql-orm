# Configuration

```yaml
# config/packages/graphql_orm.yaml
graphql_orm:
    endpoint: 'http://localhost:4000/graphql'
    max_depth: 3

    # Optional: send root arguments as typed GraphQL variables (see "Variables" below)
    variables:
        enabled: false
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
        mode: disabled # exception | warning | disabled

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
| `endpoint`                        | *(required)*             | GraphQL API endpoint                    |
| `max_depth`                       | `2`                      | Relation levels loaded by the automatic selection; deeper relations only get their identifier (an explicit `select()` is not limited) |
| `lazy_loading`                    | `false`                  | Load the relations reduced to their identifier on first access (see [lazy loading](#lazy-loading)) |
| `variables.enabled`               | `false`                  | Send root arguments as typed GraphQL variables |
| `variables.cache_pool`            | `cache.app`              | Cache pool storing the introspected argument types |
| `variables.ttl`                   | `3600`                   | Seconds before the schema is introspected again |
| `redact_query_values`             | `true`                   | Mask query values in logs and profiler (see [logging.md](logging.md#masked-values)) |
| `http_client`                     | `null`                   | HTTP client service id; defaults to the autowired `HttpClientInterface` |
| `dialect`                         | `DefaultDialect`         | GraphQL dialect to use                  |
| `headers`                         | `[]`                     | HTTP headers sent with every request    |
| `metadata_cache_pool`             | `cache.system`           | Cache pool for entity metadata outside debug mode (see [metadata cache](#metadata-cache)); `null` disables it |
| `headers_provider`                | `null`                   | Service id computing headers per request (see [dynamic headers](#dynamic-headers)) |
| `http_client_options.verify_host` | `true`                   | TLS host verification                   |
| `http_client_options.verify_peer` | `true`                   | TLS peer verification                   |
| `schema_validation.mode`          | `disabled`               | `exception`, `warning`, or `disabled`   |
| `mapping.entity.dir`              | `src/GraphQL/Entity`     | Directory where entities are stored     |
| `mapping.entity.namespace`        | `App\GraphQL\Entity`     | Namespace for entities                  |
| `mapping.repository.dir`          | `src/GraphQL/Repository` | Directory where repositories are stored |
| `mapping.repository.namespace`    | `App\GraphQL\Repository` | Namespace for repositories              |

## Lazy loading

Beyond `max_depth`, relations only get their identifier selected. With `lazy_loading`, they are hydrated as PHP lazy
ghosts instead: reading the identifier costs nothing, and the first access to another property loads the entity with
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

# config/packages/graphql_orm.yaml
graphql_orm:
    endpoint: 'https://api.example.com/graphql'
    http_client: graphql.client
```

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

By default, argument values are written in the query (escaped). With `variables.enabled`, the arguments of the root
field are sent as GraphQL variables instead:

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
