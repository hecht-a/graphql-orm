# Profiler

GraphQL ORM integrates with the **Symfony Web Debug Toolbar** and Profiler.

The toolbar sums up the request as Doctrine does: the APIs when there are [several](multiple-apis.md), queries, queries sent to the API (those not answered by the result
cache), different statements (their values aside), query time, hydration time, errors, invalid entities (the violations
of the [schema validation](schema-validation.md)), entities hydrated, and the hits of the result cache and of the
second-level cache with the adapter of their pools (`Redis`, `Filesystem`...), or `disabled`.

For each request, the panel shows:

- **Metrics**: number of queries, time spent in GraphQL (measured via Symfony Stopwatch), time spent hydrating the
  responses, slow queries when
  `slow_query_threshold` is set (see [logging.md](logging.md#slow-queries)), errors, entities hydrated and bytes
  received.
- **An alert** for each relation loaded one entity at a time, and when queries are repeated (see
  [below](#n-1-and-repeated-queries)).
- **The queries**, one row each: origin, root field and entity, caller, duration bar (the network, then the hydration
  in grey: whether the time goes to the API or to the mapping), response size, entities
  hydrated and status (an error in red, a slow query in yellow). Filter them by status, or search their text. A query in
  error is shown open.
- **The entities**: those of the mapping directory and those read by the queries of the request (first), as the bundle
  maps them: root field, identifier, repository, and for each property its GraphQL field, the target of a relation
  (`[]` for a collection) and its fetch mode. An entity whose mapping fails shows the error.
- **The lazy loading**: the entities left to be loaded on first access (`Fetch::Lazy`, `lazy_loading`, the references
  of Doctrine entities), by relation: how many, how many were loaded and by which queries, how many were never read and
  so cost no query.
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

  A warning tops the panel when `#[Cache]` is ignored (second-level cache disabled) or when a pool is an `ArrayAdapter`,
  whose values do not outlive the process. The panel is shown for a request reading only the second-level cache, or
  only evicting, without a query.
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
- **A timeline** of the queries during the request: overlapping bars are queries sent together, e.g. by
  `GraphqlQuery::concurrently()`.

The introspection queries of the bundle (typed variables, schema validation, the countable fields of Data API Builder)
are listed too, with the origin `introspection`: they count in the time of the request, once per cache lifetime.

The caller is the first line of the application on the way to the query. The methods of the entities are skipped, so
that a relation loaded on first access from `__toString()` or a getter points to the code reading it; a line of a
compiled Twig template is given as the line of the template itself, e.g. `task/index.html.twig:25`.

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
- **Hydration**: hydrated as entities or as a DTO, entities, relations, collections, root result count (and the field indexing it, with `indexBy`) and
  depth used; the collections by relation, with their type (`array` or `ArrayCollection`), how many and their items
- The "Copy" and "Copy as cURL" buttons (see [below](#copy-as-curl)), the caller (file and line that triggered the
  query) and the endpoint

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

## N+1 and repeated queries

A loop sending one query per entity is the classic N+1 problem. The "N+1 & repeated" tab of the panel shows two cases.

**A relation loaded on first access** (`Fetch::Lazy`, or `lazy_loading`) 3 times or more, one query each: the panel
names the relation, such as `Task::$assignee`, with its queries, their total duration and the lines reading it. Select
the relation with the entities holding it (raise `max_depth`, or select its fields with the query builder). A dialect
implementing `BatchLoadingDialect` loads the relations met in a same response with one query.

**Repeated queries**, such as a `find()` in a `foreach`: the panel groups the queries whose shape is the same once their
values are masked, and lists those sent 3 times or more, with links opening them. When they are all a `find()` of the
same entity, the panel suggests the query replacing them, e.g. `$repository->findBy(['id' => [/* the 8 identifiers */]])`
(the values stay masked):

| Sent    | Queries         | Query, values masked                      |
|---------|-----------------|-------------------------------------------|
| 3 times | #2, #3, #4      | `query { allUsers(condition: { id: *** }) { ... } }` |

To fix it, select the fields you need or raise `max_depth`, so that the relation comes with the first query, or use a
dialect loading lazy relations in batches (see [lazy loading](configuration.md#lazy-loading)).
