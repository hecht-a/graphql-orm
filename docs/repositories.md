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

// Filter by criteria
$tasks = $repository->findBy(['status' => 'active']);

// Custom query via Query Builder
$qb = $repository->createQueryBuilder();
$tasks = $qb
    ->select('title', 'user.name')
    ->where($qb->expr()->eq('status', 'active'))
    ->limit(10)
    ->getQuery()
    ->getResult();
```
