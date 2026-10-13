# Profiler

GraphQL ORM integrates with the **Symfony Web Debug Toolbar** and Profiler.

For each request, the panel shows:

- Number of GraphQL queries executed
- GraphQL errors (highlighted in red)
- Repeated queries (highlighted in yellow): queries that differ only by their values, sent 3 times or more
- Per-query details:
	- Endpoint
	- Duration (measured via Symfony Stopwatch)
	- Response size
	- Hydration stats (entities, relations, collections, max depth)
	- Generated GraphQL query (with Copy button), values masked by default (see [logging.md](logging.md#masked-values))
	- "Copy as cURL" button, to replay the query from a terminal (see [below](#copy-as-curl))
	- Interactive AST viewer
	- Variables
	- Caller (file + line that triggered the query)

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
3 times or more, with links to them:

| Sent    | Queries         | Query, values masked                      |
|---------|-----------------|-------------------------------------------|
| 3 times | #2, #3, #4      | `query { allUsers(condition: { id: *** }) { ... } }` |

To fix it, select the fields you need or raise `max_depth`, so that the relation comes with the first query, or use a
dialect loading lazy relations in batches (see [lazy loading](configuration.md#lazy-loading)).
