# Configuration

```yaml
# config/packages/graphql_orm.yaml
graphql_orm:
    endpoint: 'http://localhost:4000/graphql'
    max_depth: 3

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
| `redact_query_values`             | `true`                   | Mask query values in logs and profiler (see [logging.md](logging.md#masked-values)) |
| `http_client`                     | `null`                   | HTTP client service id; defaults to the autowired `HttpClientInterface` |
| `dialect`                         | `DefaultDialect`         | GraphQL dialect to use                  |
| `headers`                         | `[]`                     | HTTP headers sent with every request    |
| `http_client_options.verify_host` | `true`                   | TLS host verification                   |
| `http_client_options.verify_peer` | `true`                   | TLS peer verification                   |
| `schema_validation.mode`          | `disabled`               | `exception`, `warning`, or `disabled`   |
| `mapping.entity.dir`              | `src/GraphQL/Entity`     | Directory where entities are stored     |
| `mapping.entity.namespace`        | `App\GraphQL\Entity`     | Namespace for entities                  |
| `mapping.repository.dir`          | `src/GraphQL/Repository` | Directory where repositories are stored |
| `mapping.repository.namespace`    | `App\GraphQL\Repository` | Namespace for repositories              |

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
