# Console commands

## Generate an entity

```bash
php bin/console graphqlorm:make:entity Task
```

Launches an interactive wizard that generates:

- An entity class with `#[GraphqlOrm\Entity]`, `#[GraphqlOrm\Field]`, `#[GraphqlOrm\Identifier]` and
  `#[GraphqlOrm\Relation]` attributes, imported through the `GraphqlOrm` alias of the [entity
  mapping](entity-mapping.md#define-an-entity): `#[GraphqlOrm\Field(mappedFrom: 'title')]`
- A typed repository class

Supports scalar fields, nullable fields, object relations, and collection relations. Existing entities are suggested
with autocomplete when defining relations.

| Option           | Description                                                                            |
|------------------|----------------------------------------------------------------------------------------|
| `--root`         | the GraphQL root query field, the plural of the entity by default (`tasks` for `Task`) |
| `--force`, `-f`  | overwrite the entity and the repository when their files exist                         |
| `--from-schema`  | generate the properties from the GraphQL schema instead of asking them                 |
| `--manager`      | the manager whose mapping and API are used, the default one when unset                 |

With [several APIs](multiple-apis.md), `--manager=crm` writes the entity and its repository in the mapping of the
manager `crm`, and `--from-schema` reads the schema of its API.

### From the GraphQL schema

```bash
php bin/console graphqlorm:make:entity Task --from-schema
php bin/console graphqlorm:make:entity Task --from-schema --root=allTasks
```

Introspects the API instead of asking questions: the type returned by the root query field (`--root`, `tasks` by
default for `Task`), unwrapped from its connection (`items` of Data API Builder, or Relay `edges { node }`), gives the
properties.

| GraphQL                                                             | Generated property               |
|---------------------------------------------------------------------|----------------------------------|
| `Int`, `Byte`, `Short`, `Long`, `bigint`, `smallint`, `integer`     | `int`                            |
| `Float`, `Single`, `Decimal`, `numeric`, `float8`, `float4`         | `float`                          |
| `Boolean`                                                           | `bool`                           |
| `DateTime`, `Date`, `Datetime`, `timestamptz`, `timestamp`, `date`  | `\DateTimeImmutable`             |
| other scalars and enums                                             | `string`                         |
| list of scalars                                                     | `array`                          |
| object                                                              | relation (`?Target`)             |
| list of objects, connection                                         | collection relation (`Target[]`) |
| `T!`                                                                | not nullable                     |

Besides the standard scalars, those of Data API Builder, Hasura and PostGraphile are known. The identifier is the `id`
field, or else the first field of type `ID`. Interface and union fields are skipped. A type named after its table
(Hasura: `task_categories`) gives a singular PascalCase class name (`TaskCategory`). The command lists the relation
targets that do not exist yet, with the command generating them. Review the generated entity: enums are generated as
strings, and fields you do not need can be removed.

A custom scalar of the API that a [type converter](entity-mapping.md#type-converters) tells with
`#[AsTypeConverter(Siret::class, scalars: ['Siret'])]` is typed with its value object (`?Siret`, imported). The
standard scalars keep their PHP type, whatever converter tells them: `Int` stays `int`.

## Debug an entity

```bash
# Display resolved metadata (short name or FQCN)
php bin/console graphqlorm:debug:entity Task
php bin/console graphqlorm:debug:entity "App\GraphQL\Entity\Task"

# JSON output
php bin/console graphqlorm:debug:entity Task --format=json
```

Example output:

```
GraphQL ORM — Entity "Task"
============================

General
-------
FQCN:          App\GraphQL\Entity\Task
Manager:       default
GraphQL root:  tasks
Repository:    App\GraphQL\Repository\TaskRepository
Identifier:    id → id

Scalar fields
-------------
 Property   mappedFrom   PHP type
 id ★        id           int
 title       title        ?string
 dueDate     dueDate      ?DateTimeImmutable

Relations
---------
 Property   mappedFrom   Target entity   Type
 user       user         User            object
```

The `★` marks the identifier field.

## Find entities

`graphqlorm:find` queries the API with `findBy()` and shows the entities as hydrated: to try a mapping or a criterion
without writing a controller.

```bash
php bin/console graphqlorm:find Task --criteria done=false --order-by dueDate=desc --limit 5
```

```
 ---- ----------------------- ------- --------------------------- ------
  id   title                   done    dueDate                     user
 ---- ----------------------- ------- --------------------------- ------
  4    Plan the next release   false   null                        null
  3    Release the bundle      false   2026-02-01T09:00:00+00:00   #2
  1    Write the docs          false   2026-01-15T10:00:00+00:00   #1
 ---- ----------------------- ------- --------------------------- ------

3 tasks found.
```

| Option             | Description                                                                          |
|--------------------|--------------------------------------------------------------------------------------|
| `--criteria`       | `field=value`, repeatable; the value is read as JSON when it is one (`false`, `3`, `null`, `[1,3]`), as a string otherwise (`title=Write the docs`, or `title="123"` to keep a string) |
| `--order-by`       | `field=asc` or `field=desc`, repeatable                                               |
| `--limit`          | maximum number of entities, 20 by default                                             |
| `--format`, `-f`   | `table` (default) or `json`                                                           |

Fields are GraphQL names or PHP properties. A relation is shown by its identifier, a collection by its size (its
identifiers in JSON). The errors of the server are shown, with the unmapped fields pointed out.

## Send a raw query

`graphqlorm:query` sends a GraphQL query as written to the API, and prints its response as JSON: to try a query, an
argument or a field of the schema without writing code.

```bash
php bin/console graphqlorm:query 'query ($id: Int) { tasks(filter: { id: { eq: $id } }) { items { id title } } }' \
    --variables='{"id": 3}'

# From a file or a pipe, on the standard input; the API of another manager
php bin/console graphqlorm:query --manager=crm < contacts.graphql
```

```json
{
    "data": {
        "tasks": {
            "items": [
                { "id": 3, "title": "Release the bundle" }
            ]
        }
    }
}
```

| Option           | Description                                                  |
|------------------|--------------------------------------------------------------|
| `--variables`    | the variables of the query, as a JSON object: `'{"id": 3}'`  |
| `--manager`      | the manager whose API is queried, the default one when unset |
| `--hydrate`      | hydrate the response as this entity (short name or FQCN)     |
| `--format`, `-f` | with `--hydrate`: `table` (default) or `json`                |

- The JSON goes to the standard output alone, the errors of the command to the error output: it can be piped to `jq`.
- A response holding errors fails the command (exit code 1), its partial data printed with the errors; errors without
  data, an HTTP error or an unknown manager are shown as an error.
- The query goes through the manager: it is logged, and shown in the profiler of the command, its values masked there
  when `redact_query_values` is on. The response printed keeps its values.
- Nothing is hydrated: neither identity map, nor cache. With `--hydrate=Task` (short name or FQCN), the response is
  hydrated as the entity, whose root field the query must select, and printed as by `graphqlorm:find`: a table, or
  JSON with `--format=json`. Write the values in the query: `--hydrate` takes no `--variables`.

```bash
php bin/console graphqlorm:query '{ tasks(filter: { done: { eq: false } }) { items { id title done due_date } } }' --hydrate=Task
```

## Warm up the second-level cache

`graphqlorm:cache:warmup` loads the entities marked `#[GraphqlOrm\Cache]` into the second-level cache, every one or
those of an entity, page by page: see [caching](caching.md#warming-up).

```bash
php bin/console graphqlorm:cache:warmup Label --manager=main --batch-size=500
```

## Clear and describe the caches

`graphqlorm:cache:clear` evicts the result cache and the second-level cache, both by default, or `--results`,
`--key=...`, `--entities`, `--class=...`, of a manager (`--manager`) or all; `graphqlorm:cache:stats` describes their
pools, regions, entities and evictions: see [caching](caching.md#from-the-console).

```bash
php bin/console graphqlorm:cache:clear --class=Label
php bin/console graphqlorm:cache:stats --manager=main
```

## Validate the schema

```bash
php bin/console graphqlorm:schema:validate
```

Checks every entity of `mapping.entity.dir` against the live GraphQL schema and exits with a non-zero code on any
violation. See [schema-validation.md](schema-validation.md). With [several APIs](multiple-apis.md), each one is checked
against its own schema; `--manager=crm` checks one.
