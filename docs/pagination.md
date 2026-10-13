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

## Dialects

| Dialect                 | Pagination                                                                 |
|-------------------------|----------------------------------------------------------------------------|
| `DataApiBuilderDialect` | server cursors: `first`, `after`, `hasNextPage`, `endCursor`               |
| `RelayDialect`          | server cursors: `first`, `after`, `pageInfo { hasNextPage endCursor }`     |
| `PostGraphileDialect`   | server cursors: `first`, `after`, `pageInfo { hasNextPage endCursor }`     |
| `HasuraDialect`         | offsets: `limit`, `offset`; `limit()` is required                          |
| `DefaultDialect`        | none: `paginate()` throws a `LogicException`                               |

With an offset pagination, the cursor of a page is the offset of the next one (`"10"`, `"20"`...), and one row more
than the page size is requested to tell whether a next page exists, so that `hasNextPage` is exact. The navigation is
the same as with server cursors. See [dialects.md](dialects.md) to write a dialect implementing
`CursorPaginationDialect` or `OffsetPaginationDialect`.
