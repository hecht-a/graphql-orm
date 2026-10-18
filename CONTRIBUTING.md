# Contributing

## Quality checks

The Castor tasks need Castor 1.8 or later, whose embedded PHP (8.5) satisfies the PHP 8.4 requirement of the bundle,
and Docker for the tests: they run in containers built from `.docker/qa/Dockerfile`, one per supported PHP version,
whatever PHP the host has.

```bash
castor qa:all               # everything below
castor qa:cs:check          # coding standards (castor qa:cs:fix to fix them)
castor qa:phpstan           # static analysis, level max on src/ and tests/
castor qa:tests             # unit tests with PHP 8.4 and 8.5 (--php=8.4 for one, --filter=... for some tests)
castor qa:integration       # integration tests, see below (--keep leaves the servers running)
```

The tests of a PHP version installed on the host run as well with `vendor/bin/phpunit`. Test both versions before
pushing: PHP 8.4 and 8.5 differ, on the lazy objects for instance.

## Benchmarks

`tests/Benchmark` measures what the bundle costs, the network left aside: the overhead of a query and the hydration of
a large response. To tell whether a change makes a difference, tag a run on the base branch and compare to it:

```bash
vendor/bin/phpbench run --report=summary --tag=before   # on the base branch
vendor/bin/phpbench run --report=summary --ref=before   # with the change
```

The runs disable Xdebug and enable OPcache (`phpbench.json`), so that the figures are close to production.

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

`castor qa:integration` starts the servers, waits until they answer, runs the tests from the network of the servers
and stops them. Hasura is left out on an ARM host (Apple Silicon), where its engine does not start emulated: the CI
runs its tests. By hand, the ports published on the host are set by `GRAPHQL_ORM_DAB_PORT`, `GRAPHQL_ORM_HASURA_PORT`
and `GRAPHQL_ORM_POSTGRAPHILE_PORT` when the default ones are taken:

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

1. Run the quality checks (`castor qa:all`), then `castor release 4.2.0` (`--dry-run` to check first): from a clean
   branch, it renames the `[Unreleased]` section of `CHANGELOG.md` to the version and the date, e.g.
   `## [4.2.0] — 2026-10-13`, checks the release notes can be read from it, and commits it as `chore: release 4.2.0`.
   Write the `Upgrading from x.y` section of a major version before.
2. Tag the commit and push the tag, as the task tells:

   ```bash
   git tag -a v4.2.0 -m "4.2.0"
   git push origin main v4.2.0
   ```

The `Release` workflow then creates the GitHub release, named after the tag, with the changelog section of the
version as notes (`.github/scripts/changelog-section.sh`). It fails when the version has no section, and leaves a
release already created by hand untouched.
