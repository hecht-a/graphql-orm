# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The manager writes the entities as Doctrine: `persist()` a new entity, `remove()` one, change an entity read by a
  query, and `flush()` sends the mutations in one document, an entity created whose identifier another needs being
  created by a document of its own first. Only the fields changed since a query read them are sent, never a field the
  query did not select. A relation is written through its `joinField` (`#[GraphqlOrm\Relation(joinField: 'user_id')]`),
  the mutations are named after the `type` of `#[GraphqlOrm\Entity]` (the short name of the class by default). A failed
  mutation throws a `FlushException` telling those applied; the next `flush()` sends again those that failed.
  `DataApiBuilderDialect`, `PostGraphileDialect` and `HasuraDialect` implement the new `MutationDialect`.
- The profiler shows the mutations of `flush()` in their own color.
- `flush()` dispatches the lifecycle events of the entities, as Doctrine: `PrePersistEvent`, `PreRemoveEvent`,
  `PreFlushEvent`, `PreUpdateEvent` (with the `changedProperties`), `PostPersistEvent`, `PostUpdateEvent`,
  `PostRemoveEvent` and `PostFlushEvent`, and calls the lifecycle callbacks of the entities, `#[PrePersist]`,
  `#[PostPersist]`, `#[PreUpdate]`, `#[PostUpdate]`, `#[PreRemove]` and `#[PostRemove]`. What a pre event sets is
  written. An entity updated or deleted leaves the second-level cache.
- `persisted_queries: true` sends the queries of a manager as the SHA-256 hash of their text, as the automatic persisted
  queries of Apollo, and their text once the server asks for it; a server not supporting them is sent the queries in
  full from then on. The profiler marks the queries "persisted hit" or "persisted miss", and `MockGraphqlServer` asks
  for the text of every persisted query.

### Changed

- `GraphqlManagerInterface` declares `persist()`, `remove()` and `flush()`: a class implementing it must add them.

## [5.9.0] — 2026-10-18

### Added

- The profiler suggests `->with()` for a relation loaded one entity at a time, `select()`, `hydrateAs()` or
  `enableResultCache()` for a slow query or a large response, and the second-level cache for an entity found or loaded
  3 times or more.
- A "Callers" tab of the profiler groups the queries by the line of the application sending them, the costliest
  first.
- A "Replay" button of the profiler sends a query of the profile again and shows its response, in debug mode, for a
  query whose values are not masked (`redact_query_values: false`) and which is not a mutation. It needs the routes of
  the bundle.
- The `profiler` options set the alerts of the profiler: `max_queries`, the queries of a request beyond which its
  toolbar turns yellow (50 by default, as Doctrine), `max_query_time`, the milliseconds waited for GraphQL beyond which
  it does, and `repeated_queries`, the times from which repeated queries, lazy loads and entities worth the
  second-level cache are reported (3 by default).
- An "Export JSON" button of the profiler downloads the metrics, the queries and the findings of a profile, their
  values masked unless `redact_query_values` is false.

### Changed

- The timeline of the profiler shows the origin, the entity and the duration of each query, its hydration apart from
  the network, a graduated axis, and the time of the request before the first query.

### Fixed

- The time in GraphQL of the profiler and of its toolbar counts once the queries sent together, which the request
  waited for at the same time; their sum is shown apart.
- The profiler describes the entities read by the queries sent after the response (a `kernel.terminate` listener, a
  streamed response), and marks these queries "after the response".
- The profiler marks the queries of a sub-request (a fragment, a forward) with its path.
- A profile keeps the detail of the first 500 queries only: the others are counted in the metrics and the alerts,
  without their text, variables, AST nor response, so that a page sending thousands of queries does not bloat it.

## [5.8.1] — 2026-10-18

### Changed

- `graphqlorm:make:entity` generates the mapping attributes through a namespace alias, as Doctrine:
  `use GraphqlOrm\Mapping as GraphqlOrm;` and `#[GraphqlOrm\Entity(name: 'tasks', repositoryClass: ...)]`. The
  documentation maps the entities the same way.
- The messages of the exceptions, of the commands and of the profiler name the mapping attributes the same way:
  `#[GraphqlOrm\Cache]`, `#[GraphqlOrm\Embedded]`...

## [5.8.0] — 2026-10-18

### Added

- `$repository->with('assignee.company')` names the relations `find()`, `findOneBy()`, `findBy()` and `findAll()` load,
  as `with()` of the query builder: it gives a copy of the repository, which is left as it is.
- `graphqlorm:query --hydrate=Task` hydrates the response as the entity and prints the entities as `graphqlorm:find`,
  as a table or JSON (`--format=json`).
- The profiler tells the relations a query loads: those named by `with()`, or up to `max_depth`.
- The schema validation reports a property typed with a value object that no type converter converts, and checks the
  fields a converter converts use one of the scalars of its `#[AsTypeConverter(..., scalars: ['Int'])]`.
- `graphqlorm:make:entity --from-schema` types a custom scalar of the API with the value object of the type converter
  telling it (`scalars: ['Siret']`).
- Value objects spread over several fields, as the embeddables of Doctrine: a class marked `#[Embeddable]`, its fields
  mapped with `#[Field]`, held by a property mapped with `#[Embedded]`, a GraphQL object (`price { amount currency }`)
  or fields of the entity with `#[Embedded(prefix: 'total_')]`. Created through its constructor, selected, filtered
  and ordered by the path of its fields (`total.amount`), kept in the second-level cache, hydrated for the DTOs, checked
  by the schema validation and the PHPStan rules, listed by the profiler.

## [5.7.1] — 2026-10-18

### Fixed

- The `#[AfterHydrate]` hooks of an entity completed after being hydrated in part threw a `PartialEntityException` with
  PHP 8.4, whose `ReflectionProperty::isInitialized()` runs the initializer of a lazy object.
- No deprecation with symfony/dependency-injection 8.1, which deprecates the index and priority methods of the tagged
  iterators given by position.

## [5.7.0] — 2026-10-18

### Added

- `with()` on the query builder names the relations a query loads, nested ones by their path
  (`with('assignee.company', 'label')`), in place of `max_depth`: the relations not named only get their identifier
  selected. A relation mapped with `Fetch::Lazy` is loaded when named, and the relations named are added to the fields
  of `select()`.
- `graphqlorm:query` sends a raw GraphQL query to the API of a manager (`--manager`), with its variables
  (`--variables='{"id": 1}'`) or from the standard input, and prints the JSON response. A response holding errors fails
  the command; the query is logged and profiled, its values masked there as any other.
- `hydrateAs()` on the query builder hydrates the result as read-only DTOs, created through their constructor, rather
  than entities: the query selects their fields only. A parameter maps the field of its name, or another one with
  `#[MapFrom('assignee.name')]`; one typed with a class maps a relation to a nested DTO, an array typed `list<Dto>` a
  collection. Neither identity map nor second-level cache; pagination, `indexBy()` and the result cache work as with
  entities. The profiler tells the queries hydrated as DTOs, with the DTO and the number created.
- Type converters turn a scalar of the API into a value object (`Money`, `Email`, `Siret`) and back: a service
  implementing `TypeConverterInterface`, autoconfigured, converts the properties typed with the class of its
  `#[AsTypeConverter]`, or those whose `#[Field(converter: ...)]` names it. The values of the entities and of the DTOs
  are converted, and the value objects of the filters, criteria and `indexBy` sent back. The "Entities" tab of the
  profiler tells the converter of each field.
- `graphqlorm:cache:warmup [entity]` loads the entities marked `#[Cache]` into the second-level cache, page by page
  (`--batch-size`, 100 by default), those of an entity or every one, of a manager (`--manager`) or all, and tells how
  many were hydrated and kept.
- `GraphqlCache::evictQueryResults()` evicts every result of a manager, with any pool, even shared: the results get a new
  key, as the entities with `evictEntityRegion()`. The results kept before stay valid until a first eviction.
- `graphqlorm:cache:clear` evicts the result cache and the second-level cache: both by default, or `--results`,
  `--key=...`, `--entities`, `--class=...`, of a manager (`--manager`) or all.
- `graphqlorm:cache:stats` describes the caches of each manager: the pool, adapter and lifetime of the result cache and
  of each region, the entities marked `#[Cache]`, and how many times they were evicted.

## [5.6.0] — 2026-10-16

### Added

- Several GraphQL APIs in one application, a manager each, as the entity managers of Doctrine: `graphql_orm.managers`
  takes any option of the configuration for each API, those it does not set taken from the root, and
  `default_manager` names the default one. Without `managers`, the root is the manager `default`: nothing changes for a
  single API.
- An entity belongs to the manager its `#[Entity(manager: ...)]` names, or whose entity namespace holds it, or to the
  default one. A relation between entities of two APIs throws a `LogicException`.
- `GraphqlManagerRegistry`: `getManager()`, `getManagerForClass()`, `getRepository()`, `getManagerNames()`, and
  `forManager()` for a single manager. Managers are autowired by the name of their argument (`GraphqlManagerInterface
  $crmManager`, `GraphqlCache $crmCache`); their services are named `graphql_orm.<manager>.<service>`.
- `ServiceGraphqlEntityRepository`, a repository taking the manager of its entity from the registry, generated by
  `graphqlorm:make:entity`.
- The controller arguments, form fields and autocomplete search, the normalizer, the references of Doctrine entities and
  `graphqlorm:find` use the manager of their entity; `graphqlorm:make:entity` and `graphqlorm:schema:validate` take
  `--manager`, and `graphqlorm:debug:entity` tells the manager of an entity.
- Each API keeps its own introspection, schema validation, identity map and caches, the results of two APIs sharing a
  pool kept apart; the profiler lists the APIs, tells the API of each query, judges it slow by the threshold of its API,
  and shows the options of each API in its "Configuration" tab.
- `GraphqlManager::getName()` and `getClient()`.

### Fixed

- The routes of the bundle (`@GraphqlOrmBundle/Resources/config/routes.php`) load without `symfony/form`: they
  declared the autocomplete route through `GraphqlEntityType`, whose class needs it. Without `symfony/form` there is no
  route, as there is no autocomplete field. The path is also `EntityAutocompleteController::PATH`.

---

## [5.5.0] — 2026-10-16

### Added

- Result cache: `GraphqlQuery::enableResultCache(lifetime, key)` keeps the response of the query in
  `graphql_orm.result_cache.pool` (`cache.app` by default, any Symfony cache pool, Redis included) and answers from it,
  as Doctrine. Each page of a paginated query is kept apart; a response with errors is not kept.
- Second-level cache: the entities marked `#[Cache(region, lifetime)]` are kept once hydrated in full, and read back by
  `find()`, the references and the lazy loads, their relations kept by their identifier and loaded on first access.
  Disabled by default, as in Doctrine: `graphql_orm.second_level_cache.enabled` turns it on, and sets the default pool
  and lifetime, and regions with a pool and a lifetime of their own.
- `GraphqlCache` (also `GraphqlManager::getCache()`): `evictEntity()`, `evictEntityRegion()` (with any pool),
  `evictQueryResult()` and `containsEntity()`.
- Profiler: a "Cache" tab giving the pools of the caches with their adapter (Redis, APCu, Filesystem...) and lifetime,
  the values read, written and deleted with their time and size, the results by query (outcome, key, lifetime), the
  entities of the second-level cache with what looked them up (`find()`, a lazy load, a query), the queries added by
  the relations of entities read from the cache, and the evictions with the line calling them. It warns when
  `#[Cache]` is ignored or a pool is an `ArrayAdapter`, and shows a request reading the caches without a query.
- Toolbar: summed up as Doctrine does, with the queries sent to the API, the different statements, the hydration time,
  the invalid entities, the entities hydrated, and the hits of each cache with the adapter of its pools.

---

## [5.4.0] — 2026-10-16

### Added

- Profiler: a "Lazy loading" tab summing up, by relation, the entities left to be loaded on first access (`Fetch::Lazy`,
  `lazy_loading`, references of Doctrine entities): how many, how many were loaded and by which queries, how many were
  never read. The references of Doctrine entities are named after their property (`Invoice::$task`) in it and in the N+1
  detection.
- Profiler: a "Serializer" tab counting the relations written as identifiers and the partial entities written by
  `GraphqlEntityNormalizer`, and listing the lazy loads sent while the serializer was writing an entity (an N+1 hidden in
  a response), with a warning on top of the panel and a `serializer` mark on those queries.
- Profiler: a "Partial entities" tab listing the entities hydrated from a selection of their fields, by class and fields
  not selected, with the queries hydrating them and the `PartialEntityException` thrown.
- Profiler: the "Configuration" tab tells, for each cache of introspection results (types of the variables, countable
  fields, schema validation), whether it served the request or introspected the schema, and the time spent.
- Profiler: the "Hydration" detail of a query lists the collections hydrated by relation, with their type, how many and
  their items, and the field indexing the result (`indexBy`).
- Profiler: an "Autocomplete" tab describing the searches of autocomplete fields (entity, fields searched, text typed,
  masked unless `redact_query_values` is false, results and limit); their queries have the origin `autocomplete`
  instead of `__invoke`.

---

## [5.3.0] — 2026-10-16

### Added

- `GraphqlEntityType` loads only the submitted entities on submission, by their identifier within the restriction of
  `query_builder`, and supports `choice_lazy`. With `autocomplete: true` and `search_fields`, it searches the entities as
  the user types with Symfony UX Autocomplete (as many results as its `max_results` option), through a signed endpoint
  of the bundle (import
  `@GraphqlOrmBundle/Resources/config/routes.php`).
- `GraphqlQueryBuilder::andWhere()`, adding a condition to those of `where()`.
- `GraphqlEntityRepository::getReference()`, as Doctrine: the entity of an identifier without a query, loaded on first
  access.
- `#[GraphqlReference]`: a Doctrine entity references a GraphQL entity by an identifier column. The bundle sets it on
  load with a reference loaded on first access, and fills the column from the reference before the first persist.
  `doctrine/orm` is suggested.
- A relation typed `Collection` of `doctrine/collections` (suggested) is hydrated as an `ArrayCollection`, its item
  type read from the generic type (`/** @var Collection<int, Task> */`). The `array` properties stay arrays.
- `GraphqlEntityNormalizer`, registered with `symfony/serializer`: a relation loaded on first access and not read yet is
  written as its identifier, a partial entity with its selected fields only (not the default values of the others),
  without any query. The `ObjectNormalizer` would
  load each lazy relation (an N+1 hidden in a JSON response) or fail on the fields not selected. `symfony/serializer`
  is suggested.
- Reading a property that the query did not select, and that has no default value, throws a `PartialEntityException`
  naming the properties left out and how to load them (select them, raise `max_depth`, `Fetch::Lazy`), instead of the
  "must not be accessed before initialization" error of PHP. A partial entity met again in full is completed.
- `indexBy`, as Doctrine: `findBy([], indexBy: 'code')` and `$qb->indexBy('code')` key the result (or the items of a
  page) by a field of its entities, a GraphQL name or a PHP property. An unmapped field throws an
  `InvalidArgumentException` suggesting the closest one.


### Changed

- The outcome of the schema validation is cached in `variables.cache_pool` for `variables.ttl`: the schema is no longer
  introspected on every request when each one runs a container of its own (PHP without a worker, in dev). Editing an
  entity checks the mapping again at once.
---

## [5.2.0] — 2026-10-15

### Added

- Profiler: the GraphQL queries are highlighted: keywords, fields, arguments, types, variables, strings, numbers and
  masked values, in the colors of the profiler theme.
- Profiler: repeated queries that are all a `find()` of the same entity come with the `findBy()` loading them at once,
  on the identifier of the entity. `GraphqlQueryTrace::$identifierProperty` names it when the profiler is enabled.
- Profiler: an "Entities" tab describing the mapped entities as the bundle maps them (root field, identifier, repository,
  properties with their GraphQL field, relation target and fetch mode), those read by the request first.
- Profiler: the time spent hydrating each response, apart from the network: a "Hydration" metric, a grey part in the
  duration bar of each query, and the time in its "Hydration" tab. `GraphqlQueryTrace::$hydrationDuration` holds it.

### Changed

- The caller of a query skips the methods of the entities, and gives a line of a compiled Twig template as the line of
  the template: a relation loaded on first access from `task.assignee` in a template points to
  `task/index.html.twig:25`, not to the `__toString()` of the entity nor to a file of the Twig cache.

---

## [5.1.1] — 2026-10-15

### Fixed

- The schema validation checked no entity with the default `mapping.entity.dir`,
  `%kernel.project_dir%/src/GraphQL/Entity`: the directory was read before `%kernel.project_dir%` was resolved, so that
  no entity was found. The metadata cache warmer, given the same list, warmed nothing either.

---

## [5.1.0] — 2026-10-15

### Added

- Profiler: the origin of each query (the method of the bundle the application called, e.g. `findBy`, `getResult`, a
  custom repository method, or `lazy` for a lazy load) and the class of its entities, and a timeline of the queries
  during the request, showing the queries sent together.
- Profiler: the relations loaded on first access 3 times or more, one query each (an N+1 problem), with their queries,
  their duration and the lines reading them, in an alert, the toolbar and the "N+1 & repeated" tab. The lazy loads are
  no longer counted as repeated queries. `GraphqlQueryTrace::$lazyRelation` names the relation of a lazy load.
- Profiler: the entities that `find()` served from the shared identity map without a query, with the lines calling it,
  in a metric and an "Identity map" tab.
- Profiler: the introspection queries of the bundle (typed variables, schema validation, countable fields of Data API
  Builder), sent without the manager, are listed with the origin `introspection`.
- Profiler: the first 2 KB of each response, values masked unless `redact_query_values` is false, and a
  "Configuration" tab with the options of the bundle (header names only, no credentials) and the outcome of the schema
  validation. `GraphqlQueryTrace::$responsePreview` holds the response when the profiler is enabled.
- `GraphqlEntityRepository::getClassName()`, as Doctrine, and `GraphqlQuery::getEntityClass()`.
- `GraphqlQueryTrace::$startedAt`, `$origin` and `$entityClass`, the two last ones filled when the profiler is enabled.

### Fixed

- Profiler: opening a stored profile with slow queries failed with "Typed property ...::$slowQueryThreshold must not be
  accessed before initialization": a profile read from the storage only has its data, the threshold is now kept there.
  Fixed in 4.10.2 as well.

### Changed

- The profiler panel is reorganized: metrics first (queries, time, slow queries, errors, entities hydrated, bytes
  received), then the queries in a table, filtered by status or searched, each opening its detail in tabs (GraphQL,
  variables, AST, hydration). The repeated queries get an alert and a tab of their own. The panel follows the light or
  dark theme of the profiler.

---

## [5.0.0] — 2026-10-15

### Upgrading from 4.x

Fix the deprecations reported by 4.9 first: each of them is a change of 5.0. The
[upgrade guide](https://hecht-a.github.io/graphql-orm/upgrading) details every step.

- The mapping attributes of `GraphqlOrm\Attribute` are removed: `#[GraphqlEntity]` and `#[GraphqlField]` become
  `#[Entity]` and `#[Field]` of `GraphqlOrm\Mapping`, the `identifier` argument becomes `#[Identifier]`, a relation is
  mapped with `#[Relation]` (with its `mappedFrom`, `targetEntity`, `fetch` and `ignoreValidation`), and
  `GraphqlOrm\Attribute\Fetch` becomes `GraphqlOrm\Mapping\Fetch`. An entity still mapped with the old attributes is
  no entity anymore (`NotAGraphqlEntityException`). The PHPStan extension no longer declares the `Fetch` alias.
- New default values: `identity_map: true`, `max_depth: 1`, `variables.enabled: true`, and `schema_validation.mode`
  `warning` in the dev environment, `disabled` otherwise (tests included, rather than "in debug mode" as announced by
  4.9, so that test suites do not introspect the API). An application that set them, as 4.9 asked, sees no change;
  otherwise, mind the relations no longer selected beyond the first level (raise `max_depth`, or map them with
  `Fetch::Lazy`), and an API refusing introspection, whose values are then sent inline with a logged warning.
- `Direction::ASC` and `Direction::DESC` are removed: use the cases `Direction::Asc` and `Direction::Desc`. The values
  sent (`ASC`, `DESC`) are unchanged.
- `GraphqlManager` is final (`@final` since 4.9): depend on `GraphqlManagerInterface` to simulate or decorate it.
  `EntityHydrator` and `GraphqlEntityMetadataFactory`, internal, are final as well.
- `symfony/deprecation-contracts` is no longer a direct dependency.

### Added

- `MockGraphqlServer` answers the introspection queries of the bundle (typed variables, schema validation) with an
  empty schema, without recording them nor consuming the queued responses: the tests of an application work whatever
  these options.

---


## [4.10.3] — 2026-10-15

### Fixed

- The schema validation checked no entity with the default `mapping.entity.dir`,
  `%kernel.project_dir%/src/GraphQL/Entity`: the directory was read before `%kernel.project_dir%` was resolved, so that
  no entity was found. The metadata cache warmer, given the same list, warmed nothing either.

---

## [4.10.2] — 2026-10-15

4.10.1 is withdrawn: its tag pointed by mistake at the code of 5.0. 4.10.2 holds the fix meant for it, on top of 4.10.0.

### Fixed

- Profiler: opening a stored profile with slow queries failed with "Typed property ...::$slowQueryThreshold must not be
  accessed before initialization": a profile read from the storage only has its data, the threshold is now kept there.

---


## [4.10.0] — 2026-10-15

### Added

- `count()` with Data API Builder works on an entity whose identifier is not a number (e.g. a `string` code), or which
  has no identifier: the schema is introspected once, cached in `variables.cache_pool`, and the entity is counted on
  its first numeric field never null. Data API Builder only counts the non-null values of a numeric field, and rejected
  `count(field: <identifier>)` with "The specified argument value does not match the argument type". An entity with no
  such field gets a `LogicException` telling why. Nothing changes, and no introspection is sent, for an identifier
  typed `int` or `float`.

### Changed

- `CountingDialect::countField()` receives `null` as `$identifierField` when the property of the identifier is typed
  with neither `int` nor `float`. The dialects of the bundle other than `DataApiBuilderDialect` ignore it.

---

## [4.9.2] — 2026-10-15

### Fixed

- Profiler: "Copy" and "Copy as cURL" failed on an application served over plain HTTP (other than localhost), where
  the browser offers no Clipboard API; they now copy through a selected text area there.

---

## [4.9.1] — 2026-10-15

### Fixed

- Profiler: the AST of a query with a filter or an ordering failed to render ("Array to string conversion"); its
  arguments are now shown as JSON.
- `headers`: the dashes of the header names were turned into underscores by the configuration, so that `X-API-Key`
  was sent as `X_API_Key`.

---

## [4.9.0] — 2026-10-15

### Added

- `GraphqlManager::getRepository()`, as Doctrine `EntityManager::getRepository()`: the repository service named in the
  `repositoryClass` of `#[Entity]`, a generic one otherwise. The repositories extending
  `GraphqlEntityRepository` are found by autoconfiguration (`graphql_orm.repository` tag).
- The mapping attributes of `GraphqlOrm\Mapping`, as Doctrine `Doctrine\ORM\Mapping`: `#[Entity]` and `#[Field]`;
  `#[Identifier]` marks the identifier field, as Doctrine `#[Id]`; `#[Relation]` maps a relation or a collection, with
  `mappedFrom`, `targetEntity`, `fetch` and `ignoreValidation`. `graphqlorm:make:entity` generates them. A property
  holding an entity mapped with `#[Field]`, or mapped with two field attributes, throws an `InvalidArgumentException`.
- `GraphqlManagerInterface`, as Doctrine `EntityManagerInterface`, with `getRepository()` and `clear()`: the type to
  inject, and to simulate or decorate in the tests of an application. Autowired to `GraphqlManager`.

### Changed

- The `query_builder` option of `GraphqlEntityType`, the controller argument resolver and `graphqlorm:find` use the
  repository of the application: its own methods and overrides apply there too.

### Deprecated

- `#[GraphqlEntity]` and `#[GraphqlField]` of `GraphqlOrm\Attribute`: use `#[Entity]` and `#[Field]` of
  `GraphqlOrm\Mapping`. The `identifier` argument of `#[GraphqlField]`: add `#[Identifier]`. Mapping a relation with
  `#[GraphqlField]`, and its `targetEntity` and `fetch` arguments: use `#[Relation]`. `GraphqlOrm\Attribute\Fetch`: use
  `GraphqlOrm\Mapping\Fetch`, of which it is an alias. All still work in 4.x and are removed in 5.0, see "Upgrading from
  the mapping of 4.8" in docs/entity-mapping.md. The PHPStan extension declares the `Fetch` alias.
- `Direction::ASC` and `Direction::DESC`: use `Direction::Asc` and `Direction::Desc`, the cases being named in
  PascalCase as the other enums of the bundle. The old names are constants of the enum, pointing to the new cases, so
  that `Direction::ASC === Direction::Asc`; they are removed in 5.0. The values sent (`ASC`, `DESC`) are unchanged.
- Not setting `identity_map`, `max_depth`, `variables.enabled` and `schema_validation.mode`: their default value changes
  in 5.0 (`true`, `1`, `true`, `warning` in debug mode). Set them, see "Defaults changing in 5.0" in
  docs/configuration.md.
- Extending `GraphqlManager`: it is `@final` since 4.9 and final in 5.0, reported by the Symfony debug class loader.
  Depend on `GraphqlManagerInterface` to simulate or decorate it.

---

## [4.8.0] — 2026-10-13

### Added

- `identity_map: true` shares the entities between the queries of a request: an entity is one instance, updated by
  each query returning it, and `find()` returns one known in full without a query. Reset between requests and
  Messenger messages (`kernel.reset`); `GraphqlManager::clear()` for a long-running script. `toIterable()` keeps its
  pages out of it. Disabled by default.
- `#[GraphqlField(fetch: Fetch::Lazy)]`: a relation selected by its identifier only, whatever `max_depth`, and loaded
  on first access, the ghosts of a response together, as Doctrine `fetch: LAZY`. It needs no `lazy_loading`.
- `GraphqlQuery::batch()`: several queries on different root fields in one GraphQL document, one HTTP request. A root
  field in error fails its query only; paginated queries and queries on the same root field are rejected.

### Changed

- The AST of each query is exported for the trace without a JSON round trip: about a quarter less of the time the
  bundle spends on a small query, measured by the new benchmarks (`tests/Benchmark`, PHPBench).

---

## [4.7.0] — 2026-10-13

### Added

- A list criterion of the find methods, `count()` and the aggregates matches any of its values, as with Doctrine:
  `findBy(['id' => [1, 3]])` is sent as `in` (Data API Builder) or `_in` (Hasura); an empty list matches nothing.
  `PostGraphileDialect` rejects it with a `LogicException`, its conditions matching a single value.
- `slow_query_threshold` (milliseconds): a query lasting at least that long is logged as a warning ("Slow GraphQL
  query") and highlighted in the profiler, with a count in the toolbar. No threshold by default.
- `graphqlorm:find` console command: queries the API with `findBy()` (`--criteria field=value`, `--order-by`,
  `--limit`) and shows the entities as hydrated, as a table or JSON.

### Changed

- The duration of a query is measured by the client itself, so that it is known without the Symfony stopwatch, which
  is still fed for the profiler timeline.

### Fixed

- `findBy($criteria, [])`: an empty ordering, accepted by Doctrine, was sent as an empty `orderBy` argument, which Data
  API Builder rejects.
- `GraphqlManager::send()`, added in 4.6.0, was flagged `@internal`, which made the Symfony debug class loader report a
  deprecation for every class extending or mocking the manager.

---

## [4.6.0] — 2026-10-13

### Added

- `mappedFrom` of `#[GraphqlField]` is optional: the field is then named from its property by the naming strategy,
  `naming_strategy` in the configuration, as Doctrine does for columns. `DefaultNamingStrategy` (the default) keeps the
  property name, `SnakeCaseNamingStrategy` turns `dueDate` into `due_date`; implement `NamingStrategy` for another
  convention. The PHPStan rules take the same class in the `graphqlOrm.namingStrategy` parameter.
- Query builder filters through relations: `$expr->eq('user.name', 'User B')` is sent nested, as
  `{user: {name: {eq: ...}}}`; on a collection (`tasks.done`), it matches the entities having at least one related
  entity that matches. Data API Builder and Hasura.
- `GraphqlEntityRepository::sum()`, `avg()`, `min()` and `max()`, aggregating a field over the entities matching the
  criteria, with the dialects implementing the new `AggregatingDialect` interface: `DataApiBuilderDialect` (numeric
  fields) and `HasuraDialect`. The PHPStan rules check their field and criteria. A repository already declaring a
  method of the same name must give it the same signature, or rename it.
- `GraphqlQuery::concurrently()`, running several queries at once: every request is sent before any response is read.
  `GraphqlClient` implements the new `ConcurrentGraphqlClientInterface`; other clients run the queries one after the
  other. `GraphqlQuery` and `PaginatedResult` are covariant in their entity type: a `GraphqlQuery<Task>` is a
  `GraphqlQuery<object>`.

---

## [4.5.0] — 2026-10-13

### Added

- The PHP property names of an entity are accepted wherever a field is named, besides the GraphQL names: criteria and
  ordering of the find methods and `count()`, query builder filters and ordering, `#[MapGraphqlEntity]` mapping.
  `findBy(['dueDate' => null])` sends `due_date`. A property having the GraphQL name of another field designates that
  field, as before.
- A failing query naming fields the entity does not map points them out, with the closest mapped field:
  `Field "titel" is not mapped on App\Task: did you mean "title"?`. They are still sent, the API may accept them.
- Profiler: "Copy as cURL" copies a query, with its variables, as a command to replay it from a terminal. Headers are
  not recorded, so no token ends up in it.
- `GraphqlQuery::getSingleResult()`, the only entity a query returns, throwing a `NoResultException` when there is none
  and a `NonUniqueResultException` when there are several, as Doctrine.

### Changed

- PHPStan rules: a PHP property name is no longer reported where a field is named; the `graphqlOrm.propertyName`
  identifier is gone (remove the `@phpstan-ignore graphqlOrm.propertyName` comments, if any).

### Fixed

- An invalid query answered with an HTTP `400` (Data API Builder, PostGraphile) threw "answered with HTTP status 400"
  without the errors of the server: they are now in the exception message and the profiler.

---

## [4.4.0] — 2026-10-13

### Added

- Query builder comparisons: `gt()`, `gte()`, `lt()`, `lte()` (`_gt`... with Hasura), `isNotNull()` and `notIn()`, sent
  as one inequality per value, Data API Builder having no "not in" operator.
- `GraphqlQuery::toIterable()`, a generator going through every page of a paginated query, fetching each page once the
  previous one is read.
- Controller arguments typed with an entity receive the entity whose identifier is in the route (`{task}` or `{id}`), a
  404 response when it does not exist, or `null` for a nullable argument.
  `#[MapGraphqlEntity]` reads the identifier from another route parameter (`id`), finds the entity by other fields
  (`mapping`) or disables the resolution (`disabled`), as Doctrine `#[MapEntity]` does.
- `GraphqlEntityType`, a form field whose choices are entities, loaded with `findAll()` or the `query_builder` option,
  as Doctrine `EntityType`. Registered when symfony/form is installed.
- `MockGraphqlServer`, set as the `http_client` of the bundle in the tests of an application: it answers the queries
  with the responses queued (`respond()`, `respondWithErrors()`, `respondWithHttpStatus()`) and records them.

### Changed

- Documentation: retrying temporary errors. Symfony does not retry `POST` requests on network errors, timeouts, `500`
  or `504` by default; the configuration of the "HTTP client" section now lists `POST` for these codes.

---

## [4.3.0] — 2026-10-13

### Upgrading from 4.2

- A repository already declaring its own `count()` must give it the signature `count(array $criteria = []): int`, or
  rename it: otherwise PHP fails with a fatal error on loading it.
- Schema validation is stricter: `graphqlorm:schema:validate` may now fail on mappings it accepted, e.g. a nullable
  field mapped on a non-nullable property. Each message says how to fix the mapping.

### Added

- The profiler reports repeated queries, those differing only by their values and sent 3 times or more (the sign of an
  N+1 problem), in yellow in the toolbar and listed at the top of the panel.
- `GraphqlEntityRepository::count($criteria)`, with the dialects implementing the new `CountingDialect` interface:
  `DataApiBuilderDialect` (aggregation), `HasuraDialect` (`_aggregate`), `PostGraphileDialect` and `RelayDialect`
  (`totalCount`). The PHPStan rules check its criteria as those of `findBy()`. A repository already declaring its own
  `count()` must give it the same signature, `count(array $criteria = []): int` (that of Doctrine), or rename it.
- Schema validation (`graphqlorm:schema:validate`, first request listener) also reports a field the schema declares
  nullable mapped on a property that does not accept `null`, a relation to another type than the one of its target
  entity or mapped on a scalar, and a collection mapped on a single object (or the reverse).

### Fixed

- The profiler template defined its AST macro inside a block, deprecated since Twig 3.28 and an error with Twig 4.

---

## [4.2.0] — 2026-10-13

### Added

- `PostGraphileDialect`: Relay connections, criteria of the find methods sent as a `condition`, ordering with the
  `orderBy` enum values of the fields (`DUE_DATE_DESC`); the query builder filters, which need a plugin, are rejected.
- `EnumLiteral`, an enum value built at runtime, printed without quotes by the walkers and sent as a string in the
  variables.
- `HasuraDialect` supports `paginate()` with offsets: the cursor of a page is the offset of the next one, and one extra
  row is requested so that `hasNextPage` is exact. `OffsetPaginationDialect` marker interface for such dialects.

### Fixed

- `DataApiBuilderDialect`: going back to the first page of a pagination started at a cursor (`paginate($cursor)`)
  sent that cursor again, returning the same page instead of the first one.

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