# Logging

GraphQL ORM logs every query to a dedicated Monolog channel.

## Setup

Declare the channel in your Monolog configuration:

```yaml
# config/packages/monolog.yaml
monolog:
    channels:
        - graphql_orm
```

Logs are written to your environment's default log file (`var/log/dev.log`, etc.) at the following levels:

- `debug` — successful queries (endpoint, duration, hydrated entities, query string, caller)
- `error` — queries that returned GraphQL errors

## Example log entries

```
[debug] GraphQL query executed {
    "endpoint": "http://localhost:4000/graphql",
    "duration_ms": 42.3,
    "response_size": 1024,
    "hydrated_entities": 12,
    "hydrated_relations": 4,
    "caller": { "file": "src/Controller/HomeController.php", "line": 23 },
    "query": "query { tasks(filter: { email: { eq: \"***\" } }) { id title } }"
}

[error] GraphQL query returned errors {
    "endpoint": "http://localhost:4000/graphql",
    "errors": [{ "message": "Field 'foo' does not exist" }],
    "query": "query { tasks { foo } }"
}
```

## Masked values

Query values may hold personal data (emails, customer numbers, amounts...). By default, string and number literals,
variables and AST arguments are replaced by `***` in logs and in the profiler. Names, enum values, `true`/`false`/`null`
and variable references are kept, and the query sent to the API is not affected.

To see the values while developing:

```yaml
# config/packages/graphql_orm.yaml
when@dev:
    graphql_orm:
        redact_query_values: false
```

> Error messages returned by the API are logged as is: they may quote a value sent in the query.
