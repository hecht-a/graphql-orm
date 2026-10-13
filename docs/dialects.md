# Dialects

Dialects adapt query generation and response parsing to a specific GraphQL API flavour.

## Available dialects

| Dialect          | Class                   | Use case                   |
|------------------|-------------------------|----------------------------|
| Default          | `DefaultDialect`        | Standard GraphQL APIs (no ordering nor pagination) |
| Data API Builder | `DataApiBuilderDialect` | Microsoft Data API Builder |
| Relay            | `RelayDialect`          | Relay cursor connections (GitHub, Shopify, Apollo, Hot Chocolate…) |
| Hasura           | `HasuraDialect`         | Hasura and APIs following its `where` / `order_by` conventions |
| PostGraphile     | `PostGraphileDialect`   | PostGraphile: Relay connections, `condition`, `orderBy` enums |

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

## Hasura dialect

The `HasuraDialect` reads collections as plain lists, root field and nested collections alike, and translates the
query builder filters into a `where` boolean expression:

```php
$qb->where($qb->expr()->orX($qb->expr()->contains('title', 'docs'), $qb->expr()->eq('id', 3)))
    ->orderBy('id', Direction::DESC)
    ->limit(10);
```

```graphql
query {
  tasks(where: { _or: [{ title: { _like: "%docs%" } }, { id: { _eq: 3 } }] }, order_by: { id: desc }, limit: 10) {
    id
    title
  }
}
```

| Query builder                                      | Hasura                           |
|----------------------------------------------------|----------------------------------|
| `eq`, `neq`, `in`, `isNull`                        | `_eq`, `_neq`, `_in`, `_is_null` |
| `contains`, `startsWith`, `endsWith`               | `_like` with `%` wildcards       |
| `notContains`                                      | `_nlike` with `%` wildcards      |
| `andX`, `orX`                                      | `_and`, `_or`                    |

`%` and `_` in a searched value are escaped, so they match literally. `findBy()` criteria are sent in `where` as
`_eq` (`_is_null` for `null`). Hasura paginates with an offset: `paginate()` needs a `limit()`, and the cursor of a page
is the offset of the next one (see [pagination](pagination.md)).

## PostGraphile dialect

The `PostGraphileDialect` uses the Relay connections of PostGraphile (`edges { node }`, `first` / `after`, `pageInfo`),
sends the criteria of the find methods as a `condition`, and orders with the `orderBy` enum values PostGraphile derives
from the field names:

```php
$tasks = $repository->findBy(['done' => false], ['dueDate' => Direction::ASC], 10);
```

```graphql
query {
  allTasks(condition: { done: false }, orderBy: [DUE_DATE_ASC], first: 10) {
    edges { node { id title dueDate } }
  }
}
```

Entities map the names PostGraphile gives: `#[GraphqlEntity('allTasks')]`, `dueDate`, `userByUserId` for a foreign
key, `tasksByUserId` for the reverse connection. A `null` criterion matches `IS NULL`. The query builder filters need
[postgraphile-plugin-connection-filter](https://github.com/graphile-contrib/postgraphile-plugin-connection-filter),
which core PostGraphile does not provide: `where()` throws a `LogicException`, and lazy relations are loaded one query
each.

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

To support `count()`, implement `CountingDialect`: `countField()` builds the root field selecting the count, printed
without collection envelope, and `readCount()` reads it from the response.

If the filter of your API supports `in()` on the identifier, implement the `BatchLoadingDialect` marker interface: the
lazy relations of a response are then loaded with one query per class instead of one per entity.

An enum value built at runtime, such as an ordering, is given as an `EnumLiteral`: walkers print it without quotes, and
variables send it as a string.

For an offset pagination, implement the `OffsetPaginationDialect` marker interface: send `limit()` + 1 rows and the
cursor as offset, as `HasuraDialect::withPagination()` does; the extra row tells a next page exists and is dropped.

For cursor pagination, also implement `CursorPaginationDialect` (`hasNextPage()`, `endCursor()`) to tell where the
page information lies; without it, they are read on the root field, as Data API Builder returns them. A walker
extending `AbstractGraphqlWalker` can override `walkCollection()` to print the envelope of nested collections.
