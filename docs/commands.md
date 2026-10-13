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

## Validate the schema

```bash
php bin/console graphqlorm:schema:validate
```

Checks every entity of `mapping.entity.dir` against the live GraphQL schema and exits with a non-zero code on any
violation. See [schema-validation.md](schema-validation.md).
