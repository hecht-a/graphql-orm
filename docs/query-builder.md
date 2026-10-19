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

An entity hydrated from a selection is partial. Reading one of its properties that the query did not select, and that
has no default value, throws a `PartialEntityException` naming the properties left out, rather than the error PHP gives
for a typed property read before initialization:

```php
$task = $qb->select('title')->getQuery()->getOneOrNullResult();
$task->title;   // selected
$task->done;    // PartialEntityException: "App\Entity\Task" was hydrated without done, ... not selected by the query
```

A property with a default value keeps it. The same goes for a relation reduced to its identifier beyond `max_depth`.
When the shared identity map later meets the entity in full, it is completed. The [profiler](profiler.md) lists the
partial entities of a request, with the fields not selected, and counts the exceptions thrown.

## Loading relations

`with()` names the relations a query loads, as a fetch join of Doctrine, in place of the global `max_depth`. The
relations it does not name only get their identifier selected:

```php
$tasks = $repository->createQueryBuilder()
    ->with('assignee.company', 'label')
    ->getQuery()
    ->getResult();
```

```graphql
query {
  tasks {
    id
    title
    assignee { id name company { id name } }
    label { id name }
    reviewer { id }
  }
}
```

- A nested relation is named by its path, of GraphQL names or PHP properties, whatever `max_depth`: `assignee.company`
  loads `assignee` too.
- A relation mapped with `GraphqlOrm\Fetch::Lazy` is loaded when named.
- A relation not named is hydrated as a lazy ghost when it is mapped with `GraphqlOrm\Fetch::Lazy` or `lazy_loading` is
  enabled, as a [partial entity](#field-selection) otherwise.
- Each call adds to the relations of the previous ones. Along with `select()`, the relations named are added to the
  fields selected, with every field of their entity.
- A name that is not a relation of its entity throws an `InvalidArgumentException`, which suggests the closest field.

The find methods of a repository take them the same way: `with()` gives a copy of the repository loading these
relations, the repository itself, a shared service, being left as it is.

```php
$tasks = $taskRepository->with('assignee.company', 'label')->findBy(['done' => false]);
$task = $taskRepository->with('assignee')->find(3);
```

`find()` still serves an entity the identity map or the second-level cache knows as it was loaded, its relations
loaded on first access.

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

`where()` replaces the filter of the previous calls; `andWhere()` adds a condition to it, as Doctrine `andWhere()`:

```php
$qb->where($expr->eq('done', false))
    ->andWhere($expr->eq('priority', 'high')); // done = false and priority = high
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

The field is a GraphQL name or a PHP property; each call adds an ordering to those of the previous ones. Ordering
requires a dialect that supports it (`DefaultDialect` and `RelayDialect` throw a `LogicException`), see
[dialects.md](dialects.md).

`count()` counts the entities the filter of `where()` matches, with a query of its own, as `count()` of the repository
(see [counting](repositories.md)):

```php
$open = $queryBuilder->where($queryBuilder->expr()->eq('done', false))->count();
```

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
`GraphqlClientInterface` but not `ConcurrentGraphqlClientInterface` runs the queries one after the other.

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

## DTO results

`hydrateAs()` hydrates the result as read-only objects created through their constructor, rather than entities, as
`NEW` in Doctrine DQL. The query selects the fields of the DTO only:

```php
use GraphqlOrm\Mapping as GraphqlOrm;

final readonly class TaskSummary
{
    public function __construct(
        public int $id,
        public string $title,
        public ?\DateTimeImmutable $dueDate,
        #[GraphqlOrm\MapFrom('assignee.name')]
        public ?string $assigneeName = null,
    ) {
    }
}

$qb = $repository->createQueryBuilder();
$summaries = $qb
    ->where($qb->expr()->eq('done', false))
    ->hydrateAs(TaskSummary::class)
    ->getQuery()
    ->getResult(); // list<TaskSummary>
```

```graphql
query {
  tasks(filter: { done: { eq: false } }) {
    items { id title due_date assignee { name id } }
  }
}
```

- Each parameter of the constructor maps the field of its name, PHP property or GraphQL name. `#[GraphqlOrm\MapFrom]`
  maps another one, or a field of a relation by its path (`assignee.name`), `null` when the relation is.
- A parameter typed with a class maps a relation to a nested DTO; an array typed `list<TaskLine>`, in the `@param` of
  the constructor or the `@var` of a promoted property, maps a collection to a list of DTOs:

  ```php
  final readonly class UserCard
  {
      /**
       * @param list<TaskLine> $tasks
       */
      public function __construct(
          public string $name,
          public ?CompanyRef $company,
          public array $tasks = [],
      ) {
      }
  }
  ```

- The values are converted as for an entity: dates, backed enums, scalars. A missing or null value takes the default
  value of its parameter, or `null` when the parameter allows it; otherwise a `CastException` names the parameter.
- The DTOs are not entities: neither identity map, nor second-level cache, nor lazy loading, nor partial entities.
- `getResult()`, `getOneOrNullResult()`, `getSingleResult()`, `paginate()`, `toIterable()`, `indexBy()` (on a field of
  the entity, selected if the DTO does not map it) and the result cache work as with entities. `select()` and `with()`
  cannot be used with it, the DTO telling the fields: `getQuery()` throws a `LogicException`.
- A parameter mapping no field of the entity, or a relation without a class to hydrate, throws an
  `InvalidArgumentException` when `hydrateAs()` is called, suggesting the closest field.

## Inspecting the generated query

`name()` names the operation (`query dashboard_open_tasks { ... }`), to tell the query apart in the logs of the API; a
name that is no GraphQL name throws an `InvalidArgumentException`. See also
[operation names](configuration.md#operation-names).

```php
$qb = $repository->createQueryBuilder();
$graphql = $qb
    ->select('id', 'title')
    ->where($qb->expr()->eq('id', 1))
    ->getQuery()
    ->getGraphQL();

// outputs, with DefaultDialect:
// query {
//   tasks(id: { eq: 1 }) {
//     id
//     title
//   }
// }
```

`getGraphQL()` writes the values in the query, even when they are sent as [variables](configuration.md#variables).

## Result cache

`getQuery()->enableResultCache()` keeps the response between requests, as Doctrine `Query::enableResultCache()`; see
[caching](caching.md#result-cache).
