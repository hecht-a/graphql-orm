# Dialects

Dialects adapt query generation and response parsing to a specific GraphQL API flavour.

## Available dialects

| Dialect          | Class                   | Use case                   |
|------------------|-------------------------|----------------------------|
| Default          | `DefaultDialect`        | Standard GraphQL APIs (no ordering nor pagination) |
| Data API Builder | `DataApiBuilderDialect` | Microsoft Data API Builder |

Standard GraphQL defines no ordering nor pagination arguments: `DefaultDialect` throws a `LogicException` when
`orderBy()` or `paginate()` is used, instead of silently ignoring it. Use a dialect matching your API, or write one
(see below). `limit()` is sent as a `first` argument.

## Configure a dialect

```yaml
graphql_orm:
  dialect: GraphqlOrm\Dialect\DataApiBuilderDialect
```

## Data API Builder dialect

The `DataApiBuilderDialect` wraps results in an `items` envelope and supports cursor-based pagination with `hasNextPage`
and `endCursor`:

```graphql
query {
  tasks(first: 10) {
    items {
      id
      title
    }
    hasNextPage
    endCursor
  }
}
```

Nested collections are also wrapped automatically:

```graphql
query {
  users {
    items {
      id
      tasks {
        items {
          id
        }
      }
    }
  }
}
```

## Custom dialect

Implement `GraphqlQueryDialect` to support any API:

```php
use GraphqlOrm\Dialect\GraphqlQueryDialect;

final class MyDialect implements GraphqlQueryDialect
{
    public function extractCollection(array $data): array { ... }
    public function createWalker(): GraphqlWalkerInterface { ... }
    public function applyQueryOptions(array $arguments, QueryOptions $options): array { ... }
    public function applyFilter(?FilterExpressionInterface $filter): array { ... }
}
```
