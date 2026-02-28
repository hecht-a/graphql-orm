# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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