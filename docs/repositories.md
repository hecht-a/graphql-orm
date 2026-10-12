# Repositories

## Define a repository

```php
/**
 * @extends GraphqlEntityRepository<Task>
 * @method Task[] findAll()
 * @method Task[] findBy(array $criteria)
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

// Custom query via Query Builder
$qb = $repository->createQueryBuilder();
$tasks = $qb
    ->select('title', 'user.name')
    ->where($qb->expr()->eq('status', 'active'))
    ->limit(10)
    ->getQuery()
    ->getResult();
```

Criteria and ordering keys are GraphQL field names (`mappedFrom`), not PHP property names. Each criterion is an
equality; the dialect decides how it is sent:

| Dialect                 | `findBy(['status' => 'active', 'deletedAt' => null])`                      |
|-------------------------|-----------------------------------------------------------------------------|
| `DefaultDialect`        | `tasks(status: "active", deletedAt: null)`                                  |
| `DataApiBuilderDialect` | `tasks(filter: { status: { eq: "active" }, deletedAt: { isNull: true } })` |

`find()` requires an identifier (`#[GraphqlField(identifier: true)]`) and throws a `LogicException` otherwise.
Ordering is not supported by `DefaultDialect` (see [dialects](dialects.md)). Use the query builder for anything other
than equalities.
