# A paginated list

This page builds a controller and a Twig template paging the open tasks, 20 a page. The variant depends on the
[pagination of the dialect](../pagination.md#dialects): server cursors give "next page" links, offsets with a count give
page numbers through Pagerfanta.

## With server cursors

Data API Builder, Relay and PostGraphile paginate with cursors: a page is reached from the `endCursor` of the one
before, never by its number. The cursor travels in the query string:

```php
use GraphqlOrm\Query\Direction;
use Symfony\Component\HttpFoundation\Request;

#[Route('/tasks', name: 'task_index', methods: ['GET'])]
public function index(Request $request, TaskRepository $taskRepository): Response
{
    $after = $request->query->getString('after');

    $queryBuilder = $taskRepository->createQueryBuilder();
    $page = $queryBuilder
        ->where($queryBuilder->expr()->eq('done', false))
        ->orderBy('id', Direction::Asc)
        ->limit(20)
        ->paginate(after: $after !== '' ? $after : null)
        ->getQuery()
        ->getResult(); // PaginatedResult<Task>

    return $this->render('task/index.html.twig', ['page' => $page]);
}
```

```twig
<table class="table">
    {% for task in page.items %}
        <tr><td>{{ task.title }}</td></tr>
    {% else %}
        <tr><td>No open task.</td></tr>
    {% endfor %}
</table>

<nav class="d-flex gap-2">
    {% if page.hasPreviousPage %}
        <a class="btn btn-outline-secondary" href="{{ path('task_index') }}">First page</a>
    {% endif %}
    {% if page.hasNextPage %}
        <a class="btn btn-outline-primary" href="{{ path('task_index', {after: page.endCursor}) }}">Next page</a>
    {% endif %}
</nav>
```

`orderBy()` keeps the pages stable: order on a unique field. `RelayDialect` supports no ordering and throws a
`LogicException`: leave `orderBy()` out with it.

### Why no "previous page" link

`previous()` goes back through the cursors met by `next()`, kept in the `PaginatedResult` in memory. A new HTTP request
starts from the one cursor of its query string: `hasPreviousPage` is true, but `previous()` only knows the first page.
Across requests, offer "first page" and "next page", as above, or carry the cursors met yourself, e.g. a list of them in
the query string, the last one removed to go back.

In a command or an export, where the pages are read in one process, `toIterable()` goes through them all (see
[going through every page](../pagination.md#going-through-every-page)).

## With page numbers and Pagerfanta

A page by its number needs a total and an offset: a dialect implementing `OffsetPaginationDialect` and
`CountingDialect`, as `HasuraDialect`. With a dialect paginating with cursors, the adapter throws a `LogicException`.

```bash
composer require pagerfanta/core
```

```php
use GraphqlOrm\Pagination\Pagerfanta\GraphqlOrmAdapter;
use GraphqlOrm\Query\Direction;
use Pagerfanta\Exception\NotValidCurrentPageException;
use Pagerfanta\Pagerfanta;

#[Route('/tasks', name: 'task_index', methods: ['GET'])]
public function index(Request $request, TaskRepository $taskRepository): Response
{
    $queryBuilder = $taskRepository->createQueryBuilder()->orderBy('id', Direction::Asc);
    $queryBuilder->where($queryBuilder->expr()->eq('done', false));

    try {
        $pager = Pagerfanta::createForCurrentPageWithMaxPerPage(
            new GraphqlOrmAdapter($queryBuilder),
            $request->query->getInt('page', 1),
            20,
        );
    } catch (NotValidCurrentPageException) {
        throw $this->createNotFoundException();
    }

    return $this->render('task/index.html.twig', ['pager' => $pager]);
}
```

```twig
<table class="table">
    {% for task in pager.currentPageResults %}
        <tr><td>{{ task.title }}</td></tr>
    {% endfor %}
</table>

<nav class="d-flex gap-2 align-items-center">
    {% if pager.hasPreviousPage %}
        <a class="btn btn-outline-secondary" href="{{ path('task_index', {page: pager.previousPage}) }}">Previous</a>
    {% endif %}
    <span>Page {{ pager.currentPage }} of {{ pager.nbPages }}</span>
    {% if pager.hasNextPage %}
        <a class="btn btn-outline-primary" href="{{ path('task_index', {page: pager.nextPage}) }}">Next</a>
    {% endif %}
</nav>
```

The page costs two queries: the count, then the rows of the page by offset. A page number below 1 or beyond the last
page throws a `NotValidCurrentPageException` of Pagerfanta, a 404 above. KnpPaginator takes the query builder the same
way (see [Pagerfanta and KnpPaginator](../pagination.md#pagerfanta-and-knppaginator)).

## See also

- [Pagination](../pagination.md)
- [Dialects](../dialects.md)
- [Query Builder](../query-builder.md#ordering-and-limit)
