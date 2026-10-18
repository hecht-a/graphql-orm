# Logging

GraphQL ORM logs every query to a dedicated Monolog channel.

## Setup

Declare the channel in your Monolog configuration (without it, the bundle logs nothing):

```yaml
# config/packages/monolog.yaml
monolog:
    channels:
        - graphql_orm
```

Logs are written to your environment's default log file (`var/log/dev.log`, etc.) at the following levels:

- `debug` — successful queries (endpoint, duration, response size, hydrated entities and relations, caller, query
  string)
- `warning` — [slow queries](#slow-queries), introspections that failed (types of the
  [variables](configuration.md#variables), countable fields of Data API Builder) and
  [schema violations](schema-validation.md) in `warning` mode
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
    ...
    "query": "query { tasks { foo } }",
    "errors": [{ "message": "Field 'foo' does not exist" }]
}
```

## Slow queries

With `slow_query_threshold`, in milliseconds, a query lasting at least that long is logged as a warning, with the
threshold, instead of a debug entry; a query in error stays an error:

```yaml
# config/packages/graphql_orm.yaml
graphql_orm:
    slow_query_threshold: 500
```

```
graphql_orm.WARNING: Slow GraphQL query {"duration_ms":1007.4,...,"slow_query_threshold_ms":500.0}
```

The profiler counts them in the toolbar and marks each of them, see [profiler.md](profiler.md). The duration is
measured by the bundle, with or without the Symfony stopwatch. No threshold is set by default.

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
