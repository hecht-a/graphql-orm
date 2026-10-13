# Dialects

Dialects adapt query generation and response parsing to a specific GraphQL API flavour.

## Available dialects

| Dialect          | Class                   | Use case                   |
|------------------|-------------------------|----------------------------|
| Default          | `DefaultDialect`        | Standard GraphQL APIs (no ordering nor pagination) |
| Data API Builder | `DataApiBuilderDialect` | Microsoft Data API Builder |
| Relay            | `RelayDialect`          | Relay cursor connections (GitHub, Shopify, Apollo, Hot Chocolate…) |

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

## Relay dialect

The `RelayDialect` follows the [Relay cursor connections](https://relay.dev/graphql/connections.htm) specification:
entities are read from `edges { node }`, root field and nested collections alike, and pagination uses `first`, `after`
and `pageInfo`:

```graphql
query {
  tasks(first: 10, after: "c1") {
    edges {
      node {
        id
        title
      }
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}
```

Relay standardizes neither filtering nor ordering: criteria and query builder filters are sent as root arguments, as
with `DefaultDialect`, and `orderBy()` throws a `LogicException`. Write a dialect extending the behaviour of your API
if it orders through a specific argument.

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
    public function applyCriteria(array $criteria): array { ... }
}
```

For cursor pagination, also implement `CursorPaginationDialect` (`hasNextPage()`, `endCursor()`) to tell where the
page information lies; without it, they are read on the root field, as Data API Builder returns them. A walker
extending `AbstractGraphqlWalker` can override `walkCollection()` to print the envelope of nested collections.
