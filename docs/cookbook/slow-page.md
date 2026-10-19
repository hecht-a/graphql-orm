# Speeding up a slow page

This page starts from a slow page, a list of tasks with their assignee and labels, and goes from its profile to the
change that removes each cost: the relations loaded one at a time, the `find()` in a loop, the large responses, the
entities read on every request. Each fix is one of the [profiler](../profiler.md) findings.

## Read the profile

Open the page in the development environment, then its profile, panel "GraphQL ORM". Start with:

- **the toolbar**: the queries, those sent to the API, the time in GraphQL, and in yellow the N+1 relations, the
  repeated queries and the slow ones. `profiler.max_queries` and `profiler.max_query_time` set when it turns yellow
  (see [profiler](../profiler.md));
- **the alerts** and the "N+1 & repeated" tab: what to change, with the query replacing the others;
- **the "Callers" tab**: the queries grouped by the line sending them, the costliest first, a line of a template
  included (`task/index.html.twig:25`);
- **the "Timeline" tab**: whether the time goes to the network or to the hydration, and the time before the first query.

To see the values of the queries while developing, set `redact_query_values: false` in the development environment
only (see [masked values](../logging.md#masked-values)).

## A relation loaded one entity at a time

The "N+1 & repeated" tab names a relation loaded on first access 3 times or more, such as `Task::$assignee`: a lazy
relation (`GraphqlOrm\Fetch::Lazy`, or `lazy_loading`) read in a loop, one query per task with a dialect that does not
load them in batches. Load it with the query of the tasks:

```php
// Before: one query for the tasks, then one per assignee read by the template
$tasks = $taskRepository->findBy(['done' => false]);

// After: one query
$tasks = $taskRepository->with('assignee', 'labels')->findBy(['done' => false]);
```

The query builder takes the same `with()` (see [loading relations](../query-builder.md#loading-relations)).

## A `find()` in a loop

Repeated queries of the same shape, such as a `find()` in a `foreach`, are grouped by the panel, which gives the query
replacing them. Load the entities with one `findBy()` on the list of their identifiers:

```php
// Before: one query per identifier not known yet
foreach ($userIds as $userId) {
    $users[] = $userRepository->find($userId);
}

// After: one query, keyed by identifier
$users = $userRepository->findBy(['id' => $userIds], indexBy: 'id');
```

Once loaded, the entities are in the [shared identity map](../configuration.md#shared-identity-map): a later `find()`
of one of them in the same request sends no query.

## A large response

The detail of a slow query, or of a response over 100 KB, suggests selecting only the fields the page uses. A partial
entity keeps what was selected (see [field selection](../query-builder.md#field-selection)); a DTO maps only its
constructor (see [DTO results](../query-builder.md#dto-results)):

```php
// Partial entities
$tasks = $taskRepository->createQueryBuilder()
    ->select('id', 'title', 'assignee.name')
    ->getQuery()
    ->getResult();

// Read-only DTOs, TaskSummary being the one of the query builder page
$summaries = $taskRepository->createQueryBuilder()
    ->hydrateAs(TaskSummary::class)
    ->getQuery()
    ->getResult();
```

`select()` and `with()` cannot be combined with `hydrateAs()`: the DTO tells the fields.

Reading a property a selection left out throws a `PartialEntityException`: the "Partial entities" tab lists them.

## Entities read often and rarely changed

The tab names an entity found by identifier or loaded on first access by 3 queries or more. When it rarely changes
(labels, countries, categories), keep it in the second-level cache:

```php
use GraphqlOrm\Mapping as GraphqlOrm;

#[GraphqlOrm\Entity(name: 'labels')]
#[GraphqlOrm\Cache(region: 'reference_data')]
class Label
{
    // ...
}
```

```yaml
# config/packages/graphql_orm.yaml
graphql_orm:
    second_level_cache:
        enabled: true             # #[GraphqlOrm\Cache] is ignored otherwise
        regions:
            reference_data:
                lifetime: 86400
```

`find()`, the references and the lazy loads read it from the cache; `findBy()` and the query builder still send their
query (see [second-level cache](../caching.md#second-level-cache)).

## A query repeated across requests

A list that every request asks for, and that may be a few minutes old, keeps its response with the result cache:

```php
$qb = $taskRepository->createQueryBuilder();
$tasks = $qb
    ->where($qb->expr()->eq('done', false))
    ->getQuery()
    ->enableResultCache(lifetime: 300, key: 'tasks.open')
    ->getResult();
```

Evict it when the tasks change, with `GraphqlCache::evictQueryResult('tasks.open')` (see
[eviction](../caching.md#eviction)). The toolbar gives the hits, the "Cache" tab the pool and the operations.

## Independent lists

Two lists that do not depend on each other wait for their sum. `GraphqlQuery::concurrently()` sends them together: the
page waits for the slowest (see [concurrent queries](../query-builder.md#concurrent-queries)).

## Inspect a query

`graphqlorm:find --dry-run` shows the query a `findBy()` sends, with the envelope of the dialect and its variables,
without sending it (see [find entities](../commands.md#find-entities)):

```bash
php bin/console graphqlorm:find Task --criteria done=false --order-by dueDate=desc --limit 5 --dry-run
```

In code, `createFindByQuery()` gives the same query, not sent: `getGraphQL()` writes it (see
[built-in methods](../repositories.md#built-in-methods)). Paste it into `graphqlorm:query` to try a change of the query
against the API (see [send a raw query](../commands.md#send-a-raw-query)).

## Compare before and after

"Export JSON" in the panel downloads the profile of the queries: the summary, the queries and the findings, the values
masked. Export it before and after a change to compare them (see [export as JSON](../profiler.md#export-as-json)).

## See also

- [Profiler](../profiler.md)
- [Query Builder](../query-builder.md)
- [Caching](../caching.md)
- [Lazy loading](../configuration.md#lazy-loading)
