# Console commands

## Generate an entity

```bash
php bin/console graphqlorm:make:entity Task
```

Launches an interactive wizard that generates:

- An entity class with `#[GraphqlEntity]` and `#[GraphqlField]` attributes
- A typed repository class

Supports scalar fields, nullable fields, object relations, and collection relations. Existing entities are suggested
with autocomplete when defining relations.

### From the GraphQL schema

```bash
php bin/console graphqlorm:make:entity Task --from-schema
php bin/console graphqlorm:make:entity Task --from-schema --root=allTasks
```

Introspects the API instead of asking questions: the type returned by the root query field (`--root`, `tasks` by
default for `Task`), unwrapped from its Data API Builder connection, gives the properties.

| GraphQL                                      | Generated property                                     |
|----------------------------------------------|--------------------------------------------------------|
| `Int`, `Byte`, `Short`, `Long`               | `int`                                                  |
| `Float`, `Single`, `Decimal`                 | `float`                                                |
| `Boolean`                                    | `bool`                                                 |
| `DateTime`, `Date`                           | `\DateTimeImmutable`                                   |
| other scalars and enums                      | `string`                                               |
| list of scalars                              | `array`                                                |
| object                                       | relation (`?Target`)                                   |
| list of objects, connection                  | collection relation (`Target[]`)                       |
| `T!`                                         | not nullable                                           |

The identifier is the `id` field, or else the first field of type `ID`. Interface and union fields are skipped. The
command lists the relation targets that do not exist yet, with the command generating them. Review the generated
entity: enums are generated as strings, and fields you do not need can be removed.

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

## Validate the schema

```bash
php bin/console graphqlorm:schema:validate
```

Checks every entity of `mapping.entity.dir` against the live GraphQL schema and exits with a non-zero code on any
violation. See [schema-validation.md](schema-validation.md).
