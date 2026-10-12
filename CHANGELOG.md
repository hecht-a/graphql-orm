# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Added

- `http_client` option: id of the HTTP client service to use, e.g. a scoped client with a timeout and retries

### Changed

- `DefaultDialect` throws a `LogicException` on `orderBy()` and `paginate()`, which it used to silently ignore:
  standard GraphQL defines no ordering nor pagination arguments. Use a dialect matching your API.

---

## [2.0.0] — 2026-10-12

### Upgrading from 1.x

- `GraphqlClient` now throws `GraphqlRequestException` (a `GraphqlOrmException`) on transport failure, HTTP status
  >= 400, non-JSON body, or GraphQL errors without data. Code relying on an empty result, or catching Symfony HTTP
  client exceptions, must catch `GraphqlRequestException` instead.
- `DefaultDialect` filters are now printed as input objects: `eq('status', 'active')` generates
  `status: { eq: "active" }` (it generated the invalid `status: ["active"]`).
- An argument or input object name that is not a valid GraphQL name throws `InvalidArgumentException`.
- `max_depth` (default `2`) is now applied: the automatic selection loads fewer nested relations than before. Raise it,
  or use an explicit `select()`, to load deeper relations.
- `getOneOrNullResult()` sends `first: 1` when no limit is set: the API must accept a `first` argument.
- New dependencies: `symfony/type-info` and `phpstan/phpdoc-parser`. `symfony/console` `^7.3` is required.
- `QueryOptions::$filter` was removed (it was never read).
- `GraphqlManager::$collector` is nullable, the profiler collector only exists in debug mode, and `EntityHydrator` is
  no longer a `readonly` class.

### Added

- `graphqlorm:schema:validate` command, to validate the mapping against the schema in CI
- `SchemaIntrospector::introspectSchema()` also returns the name of the root query type
- Collection relations are detected from the property docblock (`@var Task[]`, `list<Task>`), through
  `symfony/type-info` and `phpstan/phpdoc-parser`
- Hydration of `BackedEnum`, `DateTime` and `DateTimeInterface` properties

### Changed

- `GraphqlClient` throws `GraphqlRequestException` on transport failure, HTTP status >= 400, non-JSON body, or GraphQL
  errors without data. Partial data alongside errors is still returned, errors being recorded in the query trace.
- `getOneOrNullResult()` requests a single result (`first: 1`) when no limit is set
- The profiler collector is only registered in debug mode; `GraphqlManager::$collector` is nullable
- `EntityHydrator` caches reflection per class and is no longer a `readonly` class
- Removed the unused `QueryOptions::$filter` property
- CI: tests also run against the lowest dependencies, and sources are linted on PHP 8.2

### Fixed

- GraphQL injection: string values are now escaped and argument / input object names are validated
  (`InvalidArgumentException` on an invalid name)
- `DefaultDialect`: filters were printed as lists (`status: ["active"]`) instead of input objects (`status: { eq: "active" }`)
- `DataApiBuilderDialect`: nested collections were hydrated with their `items` envelope instead of the related entities
- Hydration: invalid `float`, `bool` and `string` values now throw `CastException` instead of a `TypeError`
  (or, for `string`, silently storing the exception text)
- Identity map was ignored when the identifier was not the first mapped property
- PHP 8.2 compatibility: removed a typed class constant (PHP 8.3 syntax)
- `symfony/console` requirement raised to `^7.3`, needed by the invokable commands and `#[Argument]` / `#[Option]`
- Documentation: `Direction::DESC` case and `getGraphQL()` example output
- `max_depth` was ignored: relations beyond it are now reduced to their identifier in the automatic selection
- Metadata: a `targetEntity` on a union-typed property crashed, and untyped mapped properties were silently ignored
- Pagination: fetching another page altered the options of the original query; a `null` root returned an array instead
  of an empty `PaginatedResult`
- Profiler: variables, root result count and hydration depth were never recorded
- Schema validation: the GraphQL type is resolved from the root query field (including Data API Builder connections)
  instead of being guessed by removing a trailing "s"
- Schema validation: Data API Builder scalars (`Byte`, `Short`, `Long`, `Single`, `Decimal`) were reported as mismatches
- Schema introspection: `[Type!]!` fields were not fully unwrapped
- `InvalidGraphqlResponseException` now implements `GraphqlOrmException`
- Invalid dates now throw `CastException`
- Profiler caller: the path was shortened against a wrong project directory, frames were filtered on a `graphql-orm`
  substring (hiding projects whose path contains it), and the recorded class/function was the called one
- The container no longer needs the `filesystem` service from FrameworkBundle
- Non-finite floats (`INF`, `NAN`) are rejected instead of producing an invalid query
- `graphqlorm:make:entity` rejects property and GraphQL field names that would generate invalid code
- An entity reused from the identity map is no longer counted twice in the hydration stats

---

## [1.0.0] — 2026-02-28

### Added

#### Hydration hooks
- `#[BeforeHydrate]` — method hook called before field assignment, receives raw GraphQL data array (`802e32b`)
- `#[AfterHydrate]` — method hook called after field assignment, for computing virtual fields and post-processing (`802e32b`)
- `#[AfterHydrate]` is skipped on partial hydration to prevent accessing uninitialized properties

#### Schema validation (`1b3a404`)
- Introspection-based schema validation via `SchemaIntrospector` and `SchemaValidator`
- Checks: GraphQL type existence, field existence, PHP/GraphQL scalar compatibility
- Three modes: `exception`, `warning`, `disabled`
- Entity classes discovered automatically from `mapping.entity.dir`
- Triggered on first HTTP request via `SchemaValidationListener`

#### Field suggestions (`1474c8c`)
- "Did you mean?" suggestions using Levenshtein distance when a mapped field is not found in the schema

#### Logging (`e6d189b`)
- Dedicated `graphql_orm` Monolog channel
- `debug` level for successful queries (endpoint, duration, hydration stats, query, caller)
- `error` level for queries returning GraphQL errors

#### Query duration (`843d0a2`)
- Query duration measured via Symfony Stopwatch
- Duration exposed in the Symfony Profiler timeline per query

#### Debug command (`ca7b587`)
- `graphqlorm:debug:entity` — displays resolved metadata for an entity (table or JSON output)

#### Pagination (`54a7fde`)
- Cursor-based pagination via `->paginate()`
- `PaginatedResult<T>` with `items`, `hasNextPage`, `hasPreviousPage`, `endCursor`
- `->next()` and `->previous()` navigation helpers

#### Query Builder (`c149e49`)
- `ExpressionBuilder` with `eq`, `neq`, `contains`, `notContains`, `startsWith`, `endsWith`, `in`, `isNull`, `andX`, `orX`
- Dot notation for nested field selection (`user.name`)
- `orderBy()`, `limit()`, `addSelect()`
- Raw GraphQL query via `setGraphQL()`
- Query inspection via `->getQuery()->getGraphQL()`

#### AST (`fde7559`)
- AST-based query generation with `QueryNode`, `FieldNode`, `SelectionSetNode`
- Walker pattern with `DefaultGraphqlWalker` and `DABGraphqlWalker`

#### Dialects (`20c9dcb`)
- `DefaultDialect` for standard GraphQL APIs
- `DataApiBuilderDialect` for Microsoft Data API Builder with `items` envelope and cursor pagination
- `GraphqlQueryDialect` interface for custom implementations

#### Entity generator (`429a1cc`)
- `graphqlorm:make:entity` — interactive wizard generating entity + repository
- Autocomplete on existing entities for relation targets

#### Profiler (`f5e2a49`)
- `GraphqlOrmDataCollector` with per-query details: endpoint, duration, response size, hydration stats, AST viewer, variables, caller
- GraphQL errors highlighted in the toolbar

#### CI (`7faff8e`)
- GitHub Actions CI pipeline

#### Tests (`eb6115f`)
- PHPUnit test suite covering hydration, metadata, schema validation, introspection, hydration hooks

### Fixed

- Nested collection relations missing `items` wrapper in generated query for `DataApiBuilderDialect` (`582a0d8`)
- Empty error message when query had no arguments (`352c3c1`)
- Deprecated use of `Symfony\Component\HttpKernel\DependencyInjection\Extension` (`a306a4d`)

### Changed

- HTTP client options `verify_host` and `verify_peer` made configurable (`4f483ec`)
- Documentation moved from `README.md` to `docs/` directory (`6548bda`)
- Symfony requirement updated to `^7.1` for `#[Argument]` and `#[Option]` console attribute support
- PHP requirement set to `^8.2` for `readonly class` support

---

## [0.1.0] — 2026-02-21

Initial commit — core entity mapping, hydration, identity map, repositories, and cycle-safe relation loading.