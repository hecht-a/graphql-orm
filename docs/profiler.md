# Profiler

GraphQL ORM integrates with the **Symfony Web Debug Toolbar** and Profiler.

For each request, the panel shows:

- Number of GraphQL queries executed
- GraphQL errors (highlighted in red)
- Per-query details:
	- Endpoint
	- Duration (measured via Symfony Stopwatch)
	- Response size
	- Hydration stats (entities, relations, collections, max depth)
	- Generated GraphQL query (with Copy button)
	- Interactive AST viewer
	- Variables
	- Caller (file + line that triggered the query)

No additional configuration is needed — the collector is registered automatically when `symfony/stopwatch` is available.
