# Profiler

GraphQL ORM integrates with the **Symfony Web Debug Toolbar** and Profiler.

For each request, the panel shows:

- **Metrics**: number of queries, time spent in GraphQL (measured via Symfony Stopwatch), slow queries when
  `slow_query_threshold` is set (see [logging.md](logging.md#slow-queries)), errors, entities hydrated and bytes
  received.
- **An alert** when queries are repeated (see [below](#repeated-queries)).
- **The queries**, one row each: origin, root field and entity, caller, duration bar, response size, entities
  hydrated and status (an error in red, a slow query in yellow). Filter them by status, or search their text. A query in
  error is shown open.
- **A timeline** of the queries during the request: overlapping bars are queries sent together, e.g. by
  `GraphqlQuery::concurrently()`.

The origin is the method of the bundle the application called: `findBy`, `count`, `getResult` on a query built with
the query builder, the method of a custom repository calling the bundle, or `lazy` for a relation loaded on first
access (see [lazy loading](configuration.md#lazy-loading)).

Click a query to see its detail:

- **GraphQL**: the query sent, values masked by default (see [logging.md](logging.md#masked-values))
- **Variables**
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

## Repeated queries

A loop sending one query per entity is the classic N+1 problem: lazy relations loaded one by one, a `find()` in a
`foreach`... The panel groups the queries whose shape is the same once their values are masked, and lists those sent
3 times or more in its "Repeated" tab, with links opening them:

| Sent    | Queries         | Query, values masked                      |
|---------|-----------------|-------------------------------------------|
| 3 times | #2, #3, #4      | `query { allUsers(condition: { id: *** }) { ... } }` |

To fix it, select the fields you need or raise `max_depth`, so that the relation comes with the first query, or use a
dialect loading lazy relations in batches (see [lazy loading](configuration.md#lazy-loading)).
