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
    "query": "query { tasks { id title } }"
}

[error] GraphQL query returned errors {
    "endpoint": "http://localhost:4000/graphql",
    "errors": [{ "message": "Field 'foo' does not exist" }],
    "query": "query { tasks { foo } }"
}
```
