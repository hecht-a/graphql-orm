# Configuration

```yaml
# config/packages/graphql_orm.yaml
graphql_orm:
    endpoint: 'http://localhost:4000/graphql'
    max_depth: 3

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
| `max_depth`                       | `2`                      | Maximum nested relation loading depth   |
| `dialect`                         | `DefaultDialect`         | GraphQL dialect to use                  |
| `headers`                         | `[]`                     | HTTP headers sent with every request    |
| `http_client_options.verify_host` | `true`                   | TLS host verification                   |
| `http_client_options.verify_peer` | `true`                   | TLS peer verification                   |
| `schema_validation.mode`          | `disabled`               | `exception`, `warning`, or `disabled`   |
| `mapping.entity.dir`              | `src/GraphQL/Entity`     | Directory where entities are stored     |
| `mapping.entity.namespace`        | `App\GraphQL\Entity`     | Namespace for entities                  |
| `mapping.repository.dir`          | `src/GraphQL/Repository` | Directory where repositories are stored |
| `mapping.repository.namespace`    | `App\GraphQL\Repository` | Namespace for repositories              |
