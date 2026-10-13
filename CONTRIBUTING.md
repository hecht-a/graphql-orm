# Contributing

## Quality checks

The Castor tasks need Castor 1.8 or later, whose embedded PHP (8.5) satisfies the PHP 8.4 requirement of the bundle.

```bash
vendor/bin/phpunit          # unit tests
castor qa:cs:check          # coding standards (castor qa:cs:fix to fix them)
castor qa:phpstan           # static analysis, level max on src/ and tests/
```

## Integration tests

The `integration` group runs every dialect against a real GraphQL server, all backed by the same PostgreSQL database
filled with fictitious data (`tests/Integration/schema.sql`). It is excluded from the default run and executed by a
dedicated CI job.

| Dialect                 | Server                                    | Endpoint variable                    | Local endpoint                      |
|-------------------------|-------------------------------------------|--------------------------------------|-------------------------------------|
| `DataApiBuilderDialect` | Data API Builder                          | `GRAPHQL_ORM_DAB_ENDPOINT`           | `http://127.0.0.1:5000/graphql`     |
| `HasuraDialect`         | Hasura                                    | `GRAPHQL_ORM_HASURA_ENDPOINT`        | `http://127.0.0.1:8080/v1/graphql`  |
| `PostGraphileDialect`   | PostGraphile                              | `GRAPHQL_ORM_POSTGRAPHILE_ENDPOINT`  | `http://127.0.0.1:5001/graphql`     |
| `RelayDialect`          | PostGraphile (connections)                | `GRAPHQL_ORM_POSTGRAPHILE_ENDPOINT`  | `http://127.0.0.1:5001/graphql`     |
| `DefaultDialect`        | PostGraphile (simple collections)         | `GRAPHQL_ORM_POSTGRAPHILE_ENDPOINT`  | `http://127.0.0.1:5001/graphql`     |

```bash
docker compose -f tests/Integration/compose.yaml up -d
# wait a few seconds for the servers, then:
GRAPHQL_ORM_DAB_ENDPOINT=http://127.0.0.1:5000/graphql \
GRAPHQL_ORM_HASURA_ENDPOINT=http://127.0.0.1:8080/v1/graphql \
GRAPHQL_ORM_POSTGRAPHILE_ENDPOINT=http://127.0.0.1:5001/graphql \
vendor/bin/phpunit --group integration
docker compose -f tests/Integration/compose.yaml down
```

- `DataApiBuilder/dab-config.json` and `Hasura/metadata/` expose the tables; PostGraphile exposes them on its own.
- The tests of a server are skipped when its endpoint variable is not set.
- PostGraphile filters with a `condition` argument, which neither Relay nor standard GraphQL define: the find methods
  are covered with Data API Builder, Hasura and `PostGraphileDialect`, not with `RelayDialect` and `DefaultDialect`.
- When running PHPUnit in a container, join the compose network and use the service names as hosts, e.g.
  `--network <project>_default` and `GRAPHQL_ORM_HASURA_ENDPOINT=http://hasura:8080/v1/graphql`.

## Documentation site

The pages of `docs/` are published on GitHub Pages with [VitePress](https://vitepress.dev) by the `Documentation`
workflow, on each push to `main` touching them or `CHANGELOG.md`. To preview the site locally (Node 22):

```bash
cd docs
npm ci
npx vitepress dev
```

Links between pages stay relative Markdown links (`[Pagination](pagination.md)`), so that they work on GitHub too.

## Commits

Commit messages follow `type: message` (`feat`, `fix`, `docs`, `refactor`, `test`, `build`, `chore`), and user-facing
changes are recorded in `CHANGELOG.md`, under `[Unreleased]`.

## Releasing

1. Rename the `[Unreleased]` section of `CHANGELOG.md` to the version and the date, e.g. `## [4.2.0] — 2026-10-13`,
   with an `Upgrading from x.y` section for a major version, and commit it as `chore: release 4.2.0`.
2. Tag the commit and push the tag:

   ```bash
   git tag -a v4.2.0 -m "4.2.0"
   git push origin main v4.2.0
   ```

The `Release` workflow then creates the GitHub release, named after the tag, with the changelog section of the
version as notes (`.github/scripts/changelog-section.sh`). It fails when the version has no section, and leaves a
release already created by hand untouched.
