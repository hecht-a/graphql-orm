# Pagination

Cursor-based pagination is supported via the `paginate()` method on the Query Builder.

```php
$page = $repository->createQueryBuilder()
    ->paginate()
    ->limit(10)
    ->getQuery()
    ->getResult(); // returns PaginatedResult<Task>

// Navigate pages
$page->items;           // Task[]
$page->hasNextPage;     // bool
$page->hasPreviousPage; // bool
$page->endCursor;       // string|null

$nextPage = $page->next();     // PaginatedResult<Task>|null
$prevPage = $page->previous(); // PaginatedResult<Task>|null
```

To start from a specific cursor:

```php
$page = $repository->createQueryBuilder()
    ->paginate(after: 'cursor-string')
    ->limit(10)
    ->getQuery()
    ->getResult();
```

> Pagination support requires a compatible GraphQL dialect. See [dialects.md](dialects.md).
