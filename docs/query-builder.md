# Query Builder

The Query Builder constructs GraphQL queries programmatically.

## Field selection

```php
$qb = $repository->createQueryBuilder();

// Select specific fields (dot notation for nested fields)
$qb->select('id', 'title', 'user.name', 'user.email');

// Add fields incrementally
$qb->addSelect('dueDate');
```

## Filtering

Use `ExpressionBuilder` to build type-safe filter expressions:

```php
$qb = $repository->createQueryBuilder();
$expr = $qb->expr();

// Simple equality
$qb->where($expr->eq('status', 'active'));

// Available operators
$expr->eq('field', $value)           // field == value
$expr->neq('field', $value)          // field != value
$expr->gt('field', $value)           // field > value
$expr->gte('field', $value)          // field >= value
$expr->lt('field', $value)           // field < value
$expr->lte('field', $value)          // field <= value
$expr->contains('field', $value)     // field contains value
$expr->notContains('field', $value)  // field does not contain value
$expr->startsWith('field', $value)   // field starts with value
$expr->endsWith('field', $value)     // field ends with value
$expr->in('field', [$a, $b])         // field in [a, b]
$expr->notIn('field', [$a, $b])      // field not in [a, b], sent as field != a and field != b
$expr->isNull('field')               // field is null
$expr->isNotNull('field')            // field is not null

// Logical combinators
$expr->andX($expr->eq('status', 'active'), $expr->isNull('dueDate'))
$expr->orX($expr->eq('priority', 'high'), $expr->eq('priority', 'critical'))
```

Fields are named after their GraphQL name or their PHP property, as in the find methods, and dates and enums are sent
as in the variables: the tasks overdue are

```php
$qb->where($expr->andX($expr->isNotNull('dueDate'), $expr->lt('dueDate', new \DateTimeImmutable())));
```

`notIn()` needs at least one value. Data API Builder has no "not in" operator, hence one inequality per value; like
SQL `NOT IN`, it does not match `null`.

### Through relations

A path filters on the fields of a relation, each segment being a GraphQL name or a PHP property. On a collection, it
matches the entities having at least one related entity that matches:

```php
// The tasks of User B
$qb->where($expr->eq('user.name', 'User B'));

// The users having at least one task done
$users->where($users->expr()->eq('tasks.done', true));
```

```graphql
tasks(filter: { user: { name: { eq: "User B" } } })   # Data API Builder
tasks(where: { user: { name: { _eq: "User B" } } })   # Hasura
```

PostGraphile needs a plugin for filters (see [dialects.md](dialects.md)).

## Ordering and limit

```php
use GraphqlOrm\Query\Direction;

$qb
    ->orderBy('createdAt', Direction::Desc)
    ->limit(20);
```

Ordering requires a dialect that supports it, see [dialects.md](dialects.md).

## Keyed result

`indexBy()` keys the result by a field of its entities, a GraphQL name or a PHP property, as Doctrine `indexBy`:

```php
$labels = $labelRepository->createQueryBuilder()->indexBy('code')->getQuery()->getResult();
$labels['bug']->name;
```

The items of a page are keyed the same way. The field must hold an integer, a string, a backed enum or a `Stringable`;
two entities with the same value keep the last one. `findBy()` takes it too, by name: `findBy([], indexBy: 'code')`.

## Raw GraphQL query

You can bypass the builder entirely and provide a raw query string:

```php
$result = $repository->createQueryBuilder()
    ->setGraphQL('
        query {
            tasks {
                id
                title
            }
        }
    ')
    ->getQuery()
    ->getResult();
```


## Single result

```php
$task = $qb->where($qb->expr()->eq('id', 3))->getQuery()->getOneOrNullResult(); // Task or null
$task = $qb->where($qb->expr()->eq('id', 3))->getQuery()->getSingleResult();    // Task
```

`getOneOrNullResult()` requests one entity and returns it, or `null`. `getSingleResult()` expects exactly one: it
requests two, to tell the result is unique, and throws a `NoResultException` when there is none, a
`NonUniqueResultException` when there are several. Both keep an explicit `limit()`.

## Concurrent queries

`GraphqlQuery::concurrently()` runs several queries at once: every request is sent before any response is read, so
that a page loading independent lists waits for the slowest query rather than for their sum. It returns the results,
as `getResult()` returns them, in the same order:

```php
use GraphqlOrm\Query\GraphqlQuery;

[$tasks, $users] = GraphqlQuery::concurrently(
    $taskRepository->createQueryBuilder()->where($expr->eq('done', false))->getQuery(),
    $userRepository->createQueryBuilder()->getQuery(),
);
```

Each query is recorded in the profiler, the logs and the events as usual. When some fail, the others still complete,
then the first failure is thrown. The concurrency is the one of the Symfony HTTP client (curl): a client implementing
only `GraphqlClientInterface` runs the queries one after the other.

## Batched queries

`GraphqlQuery::batch()` sends several queries in **one** GraphQL document, one HTTP request, the server resolving
their root fields:

```php
[$tasks, $users] = GraphqlQuery::batch(
    $taskRepository->createQueryBuilder()->where($expr->eq('done', false))->getQuery(),
    $userRepository->createQueryBuilder()->getQuery(),
);
```

| | `concurrently()` | `batch()` |
|---|---|---|
| HTTP requests | one per query, in flight together | one |
| Root fields | any | different for each query |
| Pagination | yes | no |
| Values | as usual (variables when enabled) | inline |
| A query in error | fails alone | fails alone, unless the document is invalid (it then fails them all) |

The queries must come from the same manager, and be built with a query builder or the find methods. Two queries on
the same root field, or a paginated one, throw a `LogicException`: run them with `concurrently()`. The document is
recorded as one query in the profiler, the logs and the events.

## Array results

`getArrayResult()` returns the rows as the API sent them, without creating entities: lighter for an export or a JSON
response.

```php
$rows = $repository->createQueryBuilder()
    ->select('id', 'title', 'user.name')
    ->getQuery()
    ->getArrayResult();

// [['id' => 1, 'title' => 'Write docs', 'user' => ['id' => 10, 'name' => 'John']], ...]
```

Keys are GraphQL field names and values are not converted (no enums nor dates). The collection envelopes of the
dialect, such as the `items` of Data API Builder, are removed, nested collections included. A paginated query returns
the rows of the requested page.

## Inspecting the generated query

```php
$qb = $repository->createQueryBuilder();
$graphql = $qb
    ->select('id', 'title')
    ->where($qb->expr()->eq('id', 1))
    ->getQuery()
    ->getGraphQL();

// outputs:
// query {
//   tasks(id: { eq: 1 }) {
//     id
//     title
//   }
// }
```
