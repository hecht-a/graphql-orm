# Repositories

## Define a repository

```php
/**
 * @extends GraphqlEntityRepository<Task>
 */
class TaskRepository extends GraphqlEntityRepository
{
    public function __construct(GraphqlManager $manager)
    {
        parent::__construct($manager, Task::class);
    }
}
```

## Built-in methods

```php
// Fetch all entities
$tasks = $repository->findAll();

// Fetch one entity by its identifier (null when not found)
$task = $repository->find(42);

// Filter by criteria, with an optional ordering and limit
$tasks = $repository->findBy(['status' => 'active']);
$tasks = $repository->findBy(['status' => 'active'], ['createdAt' => Direction::DESC], 10);

// First entity matching the criteria (null when none)
$task = $repository->findOneBy(['status' => 'active'], ['createdAt' => Direction::DESC]);

// Number of entities matching the criteria
$count = $repository->count(['status' => 'active']);

// Custom query via Query Builder
$qb = $repository->createQueryBuilder();
$tasks = $qb
    ->select('title', 'user.name')
    ->where($qb->expr()->eq('status', 'active'))
    ->limit(10)
    ->getQuery()
    ->getResult();
```

Criteria and ordering keys are the GraphQL names of the fields (`mappedFrom`) or the PHP properties of the entity:
`findBy(['dueDate' => null])` and `findBy(['due_date' => null])` send the same query. A property having the GraphQL
name of another field designates that field. Each criterion is an equality; the dialect decides how it is sent:

| Dialect                 | `findBy(['status' => 'active', 'deletedAt' => null])`                      |
|-------------------------|-----------------------------------------------------------------------------|
| `DefaultDialect`        | `tasks(status: "active", deletedAt: null)`                                  |
| `DataApiBuilderDialect` | `tasks(filter: { status: { eq: "active" }, deletedAt: { isNull: true } })` |

`find()` requires an identifier (`#[GraphqlField(identifier: true)]`) and throws a `LogicException` otherwise.
Ordering is not supported by `DefaultDialect` (see [dialects](dialects.md)). Use the query builder for anything other
than equalities.

## Counting

`count()` sends a query of its own, with the same criteria as `findBy()`:

| Dialect                 | Count query                                                                 |
|-------------------------|-----------------------------------------------------------------------------|
| `DataApiBuilderDialect` | `tasks(filter: ...) { groupBy { aggregations { count(field: id) } } }`      |
| `HasuraDialect`         | `tasks_aggregate(where: ...) { aggregate { count } }`                       |
| `PostGraphileDialect`   | `allTasks(condition: ...) { totalCount }`                                   |
| `RelayDialect`          | `tasks(...) { totalCount }`: not in the Relay specification, but common     |
| `DefaultDialect`        | none: `count()` throws a `LogicException`                                   |

Data API Builder counts the values of a field: the entity needs an identifier. The values of a count query are sent
inline, not as GraphQL variables.

## In a controller

An argument typed with an entity receives the entity whose identifier is in the route, found with `find()`:

```php
#[Route('/tasks/{id}')]
public function show(Task $task): Response
{
    // ...
}
```

The identifier is read from the route parameter named after the argument (`{task}`), otherwise from `{id}`. An entity
not found is a 404 response, or `null` for a nullable argument (`?Task $task`). Route parameters being strings, an
integer identifier is converted, and a value that is not an integer is a 404 without query.

`#[MapGraphqlEntity]` changes how the entity is found, as Doctrine `#[MapEntity]` does:

```php
use GraphqlOrm\Attribute\MapGraphqlEntity;

// The identifier in another route parameter
#[Route('/users/{userId}/tasks/{taskId}')]
public function show(
    #[MapGraphqlEntity(id: 'userId')] User $user,
    #[MapGraphqlEntity(id: 'taskId')] Task $task,
): Response

// Other fields, found with findOneBy(): route parameter => GraphQL field name or PHP property
#[Route('/projects/{slug}')]
public function project(#[MapGraphqlEntity(mapping: ['slug' => 'slug'])] Project $project): Response

// Left to the other resolvers
public function create(#[MapGraphqlEntity(disabled: true)] Task $task): Response
```

A mapping on a field the entity does not map throws an `InvalidArgumentException`.
