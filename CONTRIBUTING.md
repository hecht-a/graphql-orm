# Contributing

## Quality checks

The Castor tasks need Castor 1.8 or later, whose embedded PHP (8.5) satisfies the PHP 8.4 requirement of the bundle.

```bash
vendor/bin/phpunit          # unit tests
castor qa:cs:check          # coding standards (castor qa:cs:fix to fix them)
castor qa:phpstan           # static analysis, level max on src/ and tests/
```

## Integration tests

The `integration` group runs the bundle against a real [Data API Builder](https://learn.microsoft.com/azure/data-api-builder/)
backed by PostgreSQL, filled with fictitious data. It is excluded from the default run and executed by a dedicated CI
job.

```bash
docker compose -f tests/Integration/DataApiBuilder/compose.yaml up -d --wait
GRAPHQL_ORM_DAB_ENDPOINT=http://127.0.0.1:5000/graphql vendor/bin/phpunit --group integration
docker compose -f tests/Integration/DataApiBuilder/compose.yaml down
```

- `schema.sql` creates and fills the tables, `dab-config.json` exposes them through GraphQL.
- The tests are skipped when `GRAPHQL_ORM_DAB_ENDPOINT` is not set.
- When running PHPUnit in a container, join the compose network and use the service name as host, e.g.
  `--network <project>_default` and `GRAPHQL_ORM_DAB_ENDPOINT=http://dab:5000/graphql`.

## Commits

Commit messages follow `type: message` (`feat`, `fix`, `docs`, `refactor`, `test`, `build`, `chore`), and user-facing
changes are recorded in `CHANGELOG.md`, under `[Unreleased]`.
