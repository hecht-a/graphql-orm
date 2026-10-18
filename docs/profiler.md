# Profiler

GraphQL ORM integrates with the **Symfony Web Debug Toolbar** and Profiler.

The toolbar sums up the request as Doctrine does: the APIs when there are [several](multiple-apis.md), queries,
queries sent to the API (those not answered by the result cache), different statements (their values aside), query
time, hydration time, errors, invalid entities (the violations of the [schema validation](schema-validation.md)),
entities hydrated (and those served from the identity map), and the hits of the result cache and of the second-level
cache with the adapter of their pools (`Redis`, `Filesystem`...), or `disabled`. When there are any, it also counts the
cache warnings, the slow queries, the N+1 relations and the repeated queries, in yellow; an error turns it red.

It also turns yellow beyond 50 queries, as Doctrine, or beyond a time in GraphQL, as set under `profiler`:

```yaml
# config/packages/dev/graphql_orm.yaml
graphql_orm:
    profiler:
        max_queries: 30       # default: 50; null reports none
        max_query_time: 500   # milliseconds waited for GraphQL; default: null, none reported
        repeated_queries: 2   # default: 3, see N+1 and repeated queries below
```

These options are those of the request, whatever the manager sending the queries: they are set at the root, not for
each manager.

For each request, the panel shows:

- **Metrics**: number of queries, time spent in GraphQL (measured via Symfony Stopwatch), the queries sent together
  (`GraphqlQuery::concurrently()`) counted once as the request waited for them at the same time, their sum shown apart,
  time spent hydrating the responses, slow queries when `slow_query_threshold` is set (see
  [logging.md](logging.md#slow-queries)), errors, entities hydrated, queries served by the result cache and entities
  served by the identity map (when there are any), and bytes received.
- **An alert** for each relation loaded one entity at a time, and when queries are repeated (see
  [below](#n-1-and-repeated-queries)).
- **The queries**, one row each: origin, root field and entity, caller, duration bar (the network, then the hydration
  in grey: whether the time goes to the API or to the mapping), response size, entities hydrated and status (an error
  in red, a slow query in yellow). Filter them by status (all, slow, errors), or search their text. A query in error is
  shown open.
- **The entities**: those of the mapping directory and those read by the queries of the request (first), as the bundle
  maps them: root field, identifier, repository, and for each property its GraphQL field, the target of a relation (`[]`
  for a collection) or its embedded value object, its fetch mode and its [type
  converter](entity-mapping.md#type-converters), named by its `#[GraphqlOrm\Field]` or found by type. An entity whose
  mapping fails shows the error.
- **The lazy loading**: the entities left to be loaded on first access (`GraphqlOrm\Fetch::Lazy`, `lazy_loading`, the
  references of Doctrine entities), by relation: how many, how many were loaded and by which queries, how many were
  never read and so cost no query.
- **The partial entities**: the entities hydrated from a [selection of their fields](query-builder.md#field-selection),
  by class and fields not selected: how many, by which queries, and how many `PartialEntityException` were thrown, for
  which properties (in warning on the tab).
- **The caches** (see [caching](caching.md)), in the "Cache" tab:
  - the pools: for the result cache, the default region and each region, the id of the pool, its adapter (the
    `TraceableAdapter` of the debug mode aside) and its lifetime;
  - the operations on each of them: values read, written and deleted, their time, and the size written (as serialized
    by PHP);
  - the queries answered by the result cache (marked `result cache` in the list, with their key and lifetime in a
    "Cache" tab of their detail);
  - the second-level cache by entity (region, hits, misses, entities kept), then entity by entity with what looked it
    up: `find()`, the lazy load of a relation, or the query that kept it;
  - the queries sent by the relations of entities read from the second-level cache (marked `from cached entity`):
    the queries the cache adds;
  - the evictions, with the line calling them, and a link to the Cache panel of Symfony.

  A warning tops the panel when `#[GraphqlOrm\Cache]` is ignored (second-level cache disabled) or when a pool is an
  `ArrayAdapter`, whose values do not outlive the process. The panel is shown for a request reading only the
  second-level cache, or only evicting, without a query.
- **The autocomplete searches** answered by the bundle (see [forms](forms.md)): entity, fields searched, text typed
  (masked unless `redact_query_values` is false), results and limit. Their queries have the origin `autocomplete`.
- **The serializer**: the relations written as identifiers and the partial entities written by
  [`GraphqlEntityNormalizer`](serializer.md), and the lazy loads sent while the serializer was writing an entity, an N+1
  problem hidden in a response, with a warning on top of the panel. Such a query is marked `serializer` in the list.
- **The identity map**: the entities that `find()` served from the shared identity map, known in full from an earlier
  query, each one a query not sent (see [shared identity map](configuration.md#shared-identity-map)).
- **The configuration** of the bundle (dialect, endpoint, options; the names of the headers only, never their values
  nor the credentials of the endpoint), and the outcome of the [schema validation](schema-validation.md): its mode, and
  the violations found on the first request. The caches of the introspection results (the types of the variables, the
  countable fields of `count()`, the schema validation) tell whether they served the request or introspected the
  schema, with the time spent.
- **The callers**: the queries grouped by the line of the application sending them, the costliest first, with their
  number, duration, origins and entities: a template or a controller sending many queries is the place to load their
  entities at once.
- **A timeline** of the queries during the request, counted from its start: each query with its origin, its entity and
  its duration, a bar for the network and one for the hydration, on a graduated axis. A hatched area shows the time
  before the first query (the kernel, the routing, the controller...); overlapping bars are queries sent together, e.g.
  by `GraphqlQuery::concurrently()`.

Besides the metrics and the alerts, each part is a tab of the panel: "Queries", "Timeline", "Callers", "Configuration",
and, when they have something to show, "Entities", "Lazy loading", "Partial entities", "Serializer", "Cache",
"Autocomplete", "Identity map" and "N+1 & repeated".

The introspection queries of the bundle (typed variables, schema validation, the countable fields of Data API Builder)
are listed too, with the origin `introspection`: they count in the time of the request, once per cache lifetime.

The caller is the first line of the application on the way to the query. The methods of the entities are skipped, so
that a relation loaded on first access from `__toString()` or a getter points to the code reading it; a line of a
compiled Twig template is given as the line of the template itself, e.g. `task/index.html.twig:25`.

A query sent by a sub-request (a fragment rendered with `render()` or ESI, a `forward()`) is marked with its path: the
profile of the sub-request holds it as well as the one of the page.

A profile keeps the detail of the first 500 queries: beyond, a query is counted in the metrics and the alerts, without
its text, variables, AST nor response, so that a page sending thousands of queries does not bloat the profile; the
list and the timeline tell how many more there were.

A query sent after the response, by a `kernel.terminate` listener or while a streamed response is sent, is kept in the
profile, with the entities it read, and marked "after the response".

The origin is the method of the bundle the application called: `findBy`, `count`, `getResult` on a query built with
the query builder, the method of a custom repository calling the bundle, or `lazy` for a relation loaded on first
access (see [lazy loading](configuration.md#lazy-loading)).

A query hydrated as DTOs with [`hydrateAs()`](query-builder.md#dto-results) carries a "DTO" badge naming the DTO, next
to its entity, and its "Entities" column counts the DTOs created instead (`8 DTOs`): it hydrates no entity.

Click a query to see its detail:

- **GraphQL**: the query sent, highlighted (keywords, fields, arguments, types, variables, values), values masked by
  default (see [logging.md](logging.md#masked-values))
- **Variables**
- **Response**: the first 2 KB of the response, indented, values masked unless `redact_query_values` is false
- **AST**: an interactive viewer of the query built by the ORM
- **Hydration**: hydrated as entities or as a DTO, the relations loaded (those named by
  [`with()`](query-builder.md#loading-relations), or up to `max_depth`), the hydration time apart from the network,
  entities, relations, collections, root result count (and the field indexing it, with `indexBy`) and depth used; the
  collections by relation, with their type (`array` or `ArrayCollection`), how many and their items
- **Cache**, for a query going through the result cache: hit or miss, its key, its lifetime and the pool
- The "Copy", "Copy as cURL" and "Replay" buttons (see [below](#copy-as-curl)), the caller (file and line that
  triggered the query) and the endpoint

No additional configuration is needed — the collector is registered automatically in debug mode (`kernel.debug`). It
is not registered otherwise, so queries are not kept in memory in production.

## Copy as cURL

"Copy as cURL" copies the query, with its variables, as a command to run in a terminal:

```bash
curl -X POST 'https://api.example.com/graphql' -H 'Content-Type: application/json' --data-raw '{"query":"...","variables":{"id":3}}'
```

Headers are not recorded: add yours, such as `-H 'Authorization: Bearer ...'`, so that no token ends up in the
profiler. The values are those shown by the profiler, masked by default: to replay the query as sent, disable
`redact_query_values` in the development environment only.

## Replay

"Replay" sends the query again, with its variables, through the manager that sent it, and shows its response and its
duration under the detail: to check a query once the API or its data changed, without loading the whole page again. It
needs the routes of the bundle:

```yaml
# config/routes/graphql_orm.yaml
graphql_orm:
    resource: '@GraphqlOrmBundle/Resources/config/routes.php'
```

The button is offered only when:

- the values of the query are not masked: with `redact_query_values` (the default), the profile only keeps
  `title: { eq: "***" }`, which cannot be sent again. Disable it in the development environment only:

  ```yaml
  # config/packages/dev/graphql_orm.yaml
  graphql_orm:
      redact_query_values: false
  ```

- the query is not a mutation, which would change the data again;
- the profile details the query (the first 500 of a request).

The route answers in debug mode only (`kernel.debug`), with the profiler enabled, and to the profiler only: it returns
a 404 otherwise. Headers are those of the manager now, given by its `headers_provider`, not those of the profiled
request.

## N+1 and repeated queries

A loop sending one query per entity is the classic N+1 problem. The "N+1 & repeated" tab of the panel shows two cases,
and the entities worth the second-level cache.

**A relation loaded on first access** (`GraphqlOrm\Fetch::Lazy`, or `lazy_loading`) 3 times or more
(`profiler.repeated_queries`), one query each: the panel names the relation, such as `Task::$assignee`, with its
queries, their total duration and the lines reading it, and suggests loading it with the query of its entities:
`->with('assignee')` on the query builder, or `$repository->with('assignee')->findBy(...)` (see
[loading relations](query-builder.md#loading-relations)). A dialect implementing `BatchLoadingDialect` loads the
relations met in a same response with one query.

**Repeated queries**, such as a `find()` in a `foreach`: the panel groups the queries whose shape is the same once their
values are masked, and lists those sent 3 times or more, with links opening them. When they are all a `find()` of the
same entity, the panel suggests the query replacing them, e.g. `$repository->findBy(['id' => [/* the 8 identifiers
*/]])` (the values stay masked):

| Sent    | Queries         | Query, values masked                      |
|---------|-----------------|-------------------------------------------|
| 3 times | #2, #3, #4      | `query { allUsers(condition: { id: *** }) { ... } }` |

To fix it, select the fields you need or raise `max_depth`, so that the relation comes with the first query, or use a
dialect loading lazy relations in batches (see [lazy loading](configuration.md#lazy-loading)).

**An entity read again and again**: an entity not marked `#[GraphqlOrm\Cache]` found by identifier or loaded on first
access by 3 queries or more is named, with its queries, and the panel suggests the
[second-level cache](caching.md#second-level-cache) if it rarely changes.

The detail of a slow query, or of a response over 100 KB, sent by the application (not a lazy load, a count or an
introspection) suggests selecting only the fields used with `select()` or `hydrateAs()`, or keeping its result with
`enableResultCache()`.
