# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [4.1.1] — 2026-10-13

### Fixed

- `DefaultDialect` wrapped nested collections in an `items` envelope, a Data API Builder syntax that standard GraphQL
  APIs reject: they are now selected as plain lists.
- Schema validation reported a mismatch, and `make:entity --from-schema` generated a `string`, for the scalars named
  after the database types by Hasura (`timestamptz`, `numeric`, `bigint`...) and for the `Datetime` of PostGraphile.
- `make:entity --from-schema` used the table name of Hasura types as relation target class (`users`): it is now a
  singular class name (`User`).

### Changed

- `symfony/string` is required: entity generation uses its inflector (it was already installed by `symfony/console`).

---

## [4.1.0] — 2026-10-13

### Added

- Lazy loading loads the ghosts of a class met in a response with one query (`in` on their identifiers, 100 per query)
  instead of one query each, with the dialects implementing the new `BatchLoadingDialect` marker interface:
  `DataApiBuilderDialect` and `HasuraDialect`.

### Changed

- `GraphqlExecutionContext::$lazyLoader`, set by the manager, loads a list of identifiers and is marked `@internal`.

---

## [4.0.1] — 2026-10-13

### Fixed

- Lazy loading: a ghost referenced twice in the same response (two entities pointing to the same relation) was marked
  as loaded with its default values, without query. A ghost is now only completed by a row holding every mapped field;
  otherwise it is loaded on first access.

---

## [4.0.0] — 2026-10-13

### Upgrading from 3.x

- PHP 8.4+ is required: lazy loading relies on PHP 8.4 lazy objects. The Symfony requirement is unchanged (7.4 or 8.x).
- Nothing else changes unless `lazy_loading` is enabled: it is disabled by default, so relations beyond `max_depth`
  still only get their identifier. When enabling it, mind the extra query sent for each lazy relation accessed (see
  the "Lazy loading" section of docs/configuration.md).
- Contributors: the Castor QA tasks need Castor 1.8 or later, whose embedded PHP satisfies the requirement.

### Added

- `lazy_loading` option (disabled by default): the relations reduced to their identifier beyond `max_depth` are
  hydrated as PHP 8.4 lazy ghosts, loaded with a query of their own on first access; `EntityNotFoundException` when
  the entity no longer exists.

### Changed

- Requires PHP 8.4+ (was PHP 8.3+).

---

## [3.2.0] — 2026-10-13

### Added

- `RelayDialect`, for APIs following the Relay cursor connections specification (`edges { node }`, `first` / `after`,
  `pageInfo`), with its `RelayGraphqlWalker`.
- `HasuraDialect`, for Hasura and APIs following its conventions: `where` with `_`-prefixed operators (`%` and `_` of
  searched values escaped), `order_by`, `limit`, plain lists; `paginate()` is rejected, Hasura paginating with an
  offset.
- PHPStan rules (`extension.neon`, enabled by phpstan/extension-installer) checking the field names given to
  `findBy()`, `findOneBy()` and the query builder against the entity mapping: PHP property name instead of GraphQL
  name, unmapped field with a suggestion (`graphqlOrm.reportUnmappedFields` to allow them), path through a field that
  is not a relation.
- `CursorPaginationDialect` interface, telling where `hasNextPage` and `endCursor` lie; implemented by
  `DataApiBuilderDialect` and `RelayDialect`.
- `AbstractGraphqlWalker::walkCollection()`, to print the envelope of nested collections.
- Schema validation and `make:entity --from-schema` unwrap Relay connections.

### Changed

- `phpstan/phpdoc-parser` is required in `^2.2` (was `^2.0`), the first version compatible with PHPStan 2.2 which
  shares it.
- `DefaultGraphqlWalker` is no longer `final`, so that dialects close to standard GraphQL can extend it.

---

## [3.1.1] — 2026-10-13

### Fixed

- `graphqlorm:make:entity` no longer adds `@method` annotations to the generated repository: they described the 2.x
  `findBy()` signature and missed `find()` and `findOneBy()`, while `@extends GraphqlEntityRepository<Entity>` already
  types every method. Existing repositories can drop them as well.
- The description of `graphqlorm:make:entity` was in French, unlike the other commands.
- Schema validation on the first request was remembered in a static property, shared by every container of the process:
  a second kernel (functional tests, a rebuilt container in worker mode) skipped it. It now runs once per container.

---

## [3.1.0] — 2026-10-13

### Added

- `graphqlorm:make:entity --from-schema`: generates the entity properties (types, nullability, identifier, relations
  and collections) from the GraphQL schema instead of asking them.
- Query events (`QueryStartedEvent`, `QueryExecutedEvent`, `QueryFailedEvent`, all extending `QueryEvent`) dispatched
  through the Symfony event dispatcher, with the query values masked. New dependency: `psr/event-dispatcher`.
- `GraphqlQuery::getArrayResult()`: rows as the API sent them, without hydration, the collection envelopes of the
  dialect removed.

### Fixed

- Queries without variables sent `"variables": []`, which Data API Builder 2 (Hot Chocolate) rejects with an HTTP 400:
  variables are now always sent as a JSON object.
- An entity first met as a nested relation reduced to its identifier (beyond `max_depth`) was returned as is when its
  complete row came later in the same response, leaving its other properties uninitialized: it is now completed, and
  its `#[AfterHydrate]` hooks run once it is.

---

## [3.0.0] — 2026-10-12

### Upgrading from 2.x

- PHP 8.3+ and Symfony 7.4 or 8.x are required.
- Logs and profiler show `***` instead of query values: set `redact_query_values: false` (e.g. under `when@dev`) to
  see them.
- `DefaultDialect` throws a `LogicException` on `orderBy()` and `paginate()` instead of ignoring them: configure the
  dialect matching your API.
- With `DataApiBuilderDialect`, `findBy()` criteria are sent as an equality `filter` instead of plain root arguments.
- A repository overriding `findBy()` must use the new signature
  `findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array`. A repository declaring its own
  `find()` or `findOneBy()` with another signature must rename it or align it on the new methods.
- A custom dialect must implement `GraphqlQueryDialect::applyCriteria()`; a custom walker extending
  `AbstractGraphqlWalker` must implement `walkRootField()`.
- `GraphqlManager` properties are private: use `getMetadataFactory()`, `getHydrator()`, `getMaxDepth()` and
  `getDialect()`, and pass the dialect to the constructor.
- `GraphqlClient`, `GraphqlQueryCompiler`, `GraphqlExecutionContext` and `GraphqlOrmDataCollector` are `final`:
  decorate `GraphqlClientInterface` instead of extending the client.
- Classes marked `@internal` may change in any release: do not use or extend them.
- `GraphqlManager` and `#[GraphqlEntity]` are no longer generic: remove `GraphqlManager<...>` type parameters from
  PHPDoc.
- Outside debug mode, entity metadata is cached in `cache.system`: clear or warm up the cache when deploying, as for the
  rest of the Symfony cache.

### Added

- Entity metadata is cached between requests outside debug mode (`metadata_cache_pool` option, `cache.system` by
  default) and warmed up by `cache:warmup`.
- `headers_provider` option: id of a service implementing `HeadersProviderInterface`, whose headers are computed
  before every request (e.g. an OAuth access token) and override the static `headers`.
- `GraphqlEntityRepository::find($id)` and `findOneBy($criteria, $orderBy)`, returning the entity or `null`.
- `http_client` option: id of the HTTP client service to use, e.g. a scoped client with a timeout and retries
- `variables` option (disabled by default): root query arguments are sent as typed GraphQL variables, their types
  being introspected from the schema and cached (`variables.cache_pool`, `variables.ttl`). Values are sent inline when
  the schema cannot be introspected or an argument is unknown. New dependency: `symfony/cache-contracts`.
- `redact_query_values` option (default `true`): query values are masked in logs and in the profiler, as they may hold
  personal data. The query sent to the API is not affected.

### Changed

- `GraphqlManager`, and the `#[GraphqlEntity]` attribute, are no longer generic: a repository constructor can type
  `GraphqlManager $manager` without PHPStan asking for a type parameter. `GraphqlManager::execute()` takes the entity
  type from its hydration callable.
- Requires PHP 8.3+ and Symfony 7.4 or 8.x (was PHP 8.2+ and Symfony 7.1+): Symfony 8 is now supported, and the
  Symfony versions no longer maintained are dropped.
- `GraphqlEntityRepository::findBy()` takes an optional ordering and limit (`findBy($criteria, $orderBy, $limit)`):
  a repository overriding `findBy()` must update its signature.
- `GraphqlQueryDialect` declares `applyCriteria()`, which turns the criteria of the repository find methods into root
  arguments: custom dialects must implement it. `DataApiBuilderDialect` now sends them as an equality `filter`
  (`isNull` for `null`) instead of plain root arguments, which Data API Builder rejected; `DefaultDialect` keeps plain
  root arguments.
- `GraphqlManager` dependencies are now private: use `getMetadataFactory()`, `getHydrator()`, `getMaxDepth()` and
  `getDialect()` instead of the former public properties, and pass the dialect to the constructor instead of assigning
  `$manager->dialect`.
- `GraphqlClient`, `GraphqlQueryCompiler`, `GraphqlExecutionContext` and `GraphqlOrmDataCollector` are now `final`:
  decorate `GraphqlClientInterface` instead of extending the client.
- Implementation classes are marked `@internal` and are not covered by the backward compatibility promise: the query
  compiler and string builder, the hydrator, the metadata classes, the schema tools, the query redactor, the commands,
  the event listener, the data collector and the DependencyInjection classes. The public API is made of the
  attributes, `GraphqlManager`, the repository, the query builder and query, the dialects, walkers and AST, the client
  interface, the expressions and the exceptions.
- `DefaultDialect` throws a `LogicException` on `orderBy()` and `paginate()`, which it used to silently ignore:
  standard GraphQL defines no ordering nor pagination arguments. Use a dialect matching your API.
- `AbstractGraphqlWalker` now implements `walk()` and declares an abstract `walkRootField()`: a custom walker extending
  it must implement `walkRootField()`. Walkers implementing `GraphqlWalkerInterface` directly are not affected (they
  just do not support variables).
- Logs and profiler show `***` instead of query values by default; set `redact_query_values: false` (e.g. under
  `when@dev`) to see them.

### Fixed

- `PaginatedResult::next()` returns `null` when the API reports a next page without an end cursor, instead of loading
  the first page again (which made a loop over `next()` endless).
- A collection row that is not an object throws an `InvalidGraphqlResponseException` instead of a PHP `TypeError`.

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