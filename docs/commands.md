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
