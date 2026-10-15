# Profiler

GraphQL ORM integrates with the **Symfony Web Debug Toolbar** and Profiler.

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
- **The identity map**: the entities that `find()` served from the shared identity map, known in full from an earlier
  query, each one a query not sent (see [shared identity map](configuration.md#shared-identity-map)).
- **The configuration** of the bundle (dialect, endpoint, options; the names of the headers only, never their values
  nor the credentials of the endpoint), and the outcome of the [schema validation](schema-validation.md): its mode, and
  the violations found on the first request.
- **A timeline** of the queries during the request: overlapping bars are queries sent together, e.g. by
  `GraphqlQuery::concurrently()`.

The introspection queries of the bundle (typed variables, schema validation, the countable fields of Data API Builder)
are listed too, with the origin `introspection`: they count in the time of the request, once per cache lifetime.

The origin is the method of the bundle the application called: `findBy`, `count`, `getResult` on a query built with
the query builder, the method of a custom repository calling the bundle, or `lazy` for a relation loaded on first
access (see [lazy loading](configuration.md#lazy-loading)).

Click a query to see its detail:

- **GraphQL**: the query sent, values masked by default (see [logging.md](logging.md#masked-values))
- **Variables**
- **Response**: the first 2 KB of the response, indented, values masked unless `redact_query_values` is false
- **AST**: an interactive viewer of the query built by the ORM
- **Hydration**: entities, relations, collections, root result count and depth used
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
values are masked, and lists those sent 3 times or more, with links opening them:

| Sent    | Queries         | Query, values masked                      |
|---------|-----------------|-------------------------------------------|
| 3 times | #2, #3, #4      | `query { allUsers(condition: { id: *** }) { ... } }` |

To fix it, select the fields you need or raise `max_depth`, so that the relation comes with the first query, or use a
dialect loading lazy relations in batches (see [lazy loading](configuration.md#lazy-loading)).
