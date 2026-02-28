# GraphQL ORM

**GraphQL ORM** is a lightweight **GraphQL ORM for PHP/Symfony**, inspired by concepts from **Doctrine ORM**.

It allows you to map PHP objects to a GraphQL API using attributes, and provides repositories, a query builder,
automatic hydration, relation handling, schema validation, and deep Symfony integration.

---

## Table of contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Entity mapping](#entity-mapping)
- [Repositories](#repositories)
- [Query Builder](#query-builder)
- [Pagination](#pagination)
- [Dialects](#dialects)
- [Schema validation](#schema-validation)
- [Logging](#logging)
- [Profiler (Symfony Web Debug Toolbar)](#profiler)
- [BeforeHydrate hook](#beforehydrate-hook)
- [AfterHydrate hook](#afterhydrate-hook)
- [Using both hooks together](#using-both-hooks-together)
- [Console commands](#console-commands)

---

## Installation

```bash
composer require hecht-a/graphql-orm
```

---

## Configuration

```yaml
# config/packages/graphql_orm.yaml
graphql_orm:
  endpoint: 'http://localhost:4000/graphql'
  max_depth: 3

  # Optional: HTTP client options
  http_client_options:
    verify_host: true
    verify_peer: true

  # Optional: static headers sent with every request
  headers:
    Authorization: 'Bearer my-token'

  # Optional: GraphQL dialect (see Dialects section)
  dialect: GraphqlOrm\Dialect\DefaultDialect

  # Optional: schema validation (see Schema validation section)
  schema_validation:
    mode: disabled # exception | warning | disabled

  mapping:
    entity:
      dir: '%kernel.project_dir%/src/GraphQL/Entity'
      namespace: App\GraphQL\Entity
    repository:
      dir: '%kernel.project_dir%/src/GraphQL/Repository'
      namespace: App\GraphQL\Repository
```

### Available options

| Option                            | Default                  | Description                             |
|-----------------------------------|--------------------------|-----------------------------------------|
| `endpoint`                        | *(required)*             | GraphQL API endpoint                    |
| `max_depth`                       | `2`                      | Maximum nested relation loading depth   |
| `dialect`                         | `DefaultDialect`         | GraphQL dialect to use                  |
| `headers`                         | `[]`                     | HTTP headers sent with every request    |
| `http_client_options.verify_host` | `true`                   | TLS host verification                   |
| `http_client_options.verify_peer` | `true`                   | TLS peer verification                   |
| `schema_validation.mode`          | `disabled`               | `exception`, `warning`, or `disabled`   |
| `mapping.entity.dir`              | `src/GraphQL/Entity`     | Directory where entities are stored     |
| `mapping.entity.namespace`        | `App\GraphQL\Entity`     | Namespace for entities                  |
| `mapping.repository.dir`          | `src/GraphQL/Repository` | Directory where repositories are stored |
| `mapping.repository.namespace`    | `App\GraphQL\Repository` | Namespace for repositories              |

---

## Entity mapping

### Define an entity

```php
use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

#[GraphqlEntity(name: 'tasks', repositoryClass: TaskRepository::class)]
class Task
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'title')]
    public string $title;

    #[GraphqlField(mappedFrom: 'dueDate')]
    public ?DateTimeImmutable $dueDate = null;

    #[GraphqlField(mappedFrom: 'user')]
    public ?User $user = null;
}
```

### `#[GraphqlEntity]`

| Parameter         | Type                 | Description                                  |
|-------------------|----------------------|----------------------------------------------|
| `name`            | `string`             | GraphQL root query field name (e.g. `tasks`) |
| `repositoryClass` | `class-string\|null` | Associated repository class                  |

### `#[GraphqlField]`

| Parameter          | Type                 | Description                                            |
|--------------------|----------------------|--------------------------------------------------------|
| `mappedFrom`       | `string`             | Field name in the GraphQL schema                       |
| `identifier`       | `bool`               | Marks this field as the entity identifier              |
| `targetEntity`     | `class-string\|null` | Explicit relation target (optional if PHP type is set) |
| `ignoreValidation` | `bool`               | Ignore the schema validation                           |

### Relations

Relations are detected automatically from the PHP property type:

```php
// Automatic detection via PHP type
#[GraphqlField(mappedFrom: 'user')]
public User $user;

// Explicit declaration
#[GraphqlField(mappedFrom: 'user', targetEntity: User::class)]
public mixed $user;

// Collection
#[GraphqlField(mappedFrom: 'tasks')]
public array $tasks = [];
```

### Supported PHP types

| PHP type            | GraphQL scalars                                            |
|---------------------|------------------------------------------------------------|
| `int`               | `Int`, `ID`                                                |
| `float`             | `Float`                                                    |
| `string`            | `String`, `ID`, `Date`, `DateTime`, `Time`, `JSON`, `UUID` |
| `bool`              | `Boolean`                                                  |
| `DateTimeImmutable` | `DateTime`, `Date`, `Time`, `String`                       |

`DateTimeImmutable` fields are automatically cast from ISO 8601 strings or Unix timestamps (in milliseconds).

---

## Repositories

### Define a repository

```php
/**
 * @extends GraphqlEntityRepository<Task>
 * @method Task[] findAll()
 * @method Task[] findBy(array $criteria)
 */
class TaskRepository extends GraphqlEntityRepository
{
    public function __construct(GraphqlManager $manager)
    {
        parent::__construct($manager, Task::class);
    }
}
```

### Built-in methods

```php
// Fetch all entities
$tasks = $repository->findAll();

// Filter by criteria
$tasks = $repository->findBy(['status' => 'active']);

// Custom query via Query Builder
$tasks = $repository->createQueryBuilder()
    ->select('title', 'user.name')
    ->where($qb->expr()->eq('status', 'active'))
    ->limit(10)
    ->getQuery()
    ->getResult();
```

---

## Query Builder

The Query Builder constructs GraphQL queries programmatically.

### Field selection

```php
$qb = $repository->createQueryBuilder();

// Select specific fields (dot notation for nested fields)
$qb->select('id', 'title', 'user.name', 'user.email');

// Add fields incrementally
$qb->addSelect('dueDate');
```

### Filtering

Use `ExpressionBuilder` to build type-safe filter expressions:

```php
$qb = $repository->createQueryBuilder();
$expr = $qb->expr();

// Simple equality
$qb->where($expr->eq('status', 'active'));

// Available operators
$expr->eq('field', $value)           // field == value
$expr->neq('field', $value)          // field != value
$expr->contains('field', $value)     // field contains value
$expr->notContains('field', $value)  // field does not contain value
$expr->startsWith('field', $value)   // field starts with value
$expr->endsWith('field', $value)     // field ends with value
$expr->in('field', [$a, $b])         // field in [a, b]
$expr->isNull('field')               // field is null

// Logical combinators
$expr->andX($expr->eq('status', 'active'), $expr->isNull('dueDate'))
$expr->orX($expr->eq('priority', 'high'), $expr->eq('priority', 'critical'))
```

### Ordering and limit

```php
use GraphqlOrm\Query\Direction;

$qb
    ->orderBy('createdAt', Direction::Desc)
    ->limit(20);
```

### Custom raw GraphQL query

You can bypass the builder entirely and provide a raw query string:

```php
$result = $repository->createQueryBuilder()
    ->setGraphQL('
        query {
            tasks {
                id
                title
            }
        }
    ')
    ->getQuery()
    ->getResult();
```

### Inspecting the generated query

```php
$graphql = $repository->createQueryBuilder()
    ->select('id', 'title')
    ->where($qb->expr()->eq('id', 1))
    ->getQuery()
    ->getGraphQL();

// outputs:
// query {
//   tasks(id: 1) {
//     id
//     title
//   }
// }
```

---

## Pagination

Cursor-based pagination is supported via the `paginate()` method on the Query Builder:

```php
$page = $repository->createQueryBuilder()
    ->paginate()
    ->limit(10)
    ->getQuery()
    ->getResult(); // returns PaginatedResult<Task>

// Navigate pages
$page->items;           // Task[]
$page->hasNextPage;     // bool
$page->hasPreviousPage; // bool
$page->endCursor;       // string|null

$nextPage = $page->next();     // PaginatedResult<Task>|null
$prevPage = $page->previous(); // PaginatedResult<Task>|null
```

To start from a specific cursor:

```php
$page = $repository->createQueryBuilder()
    ->paginate(after: 'cursor-string')
    ->limit(10)
    ->getQuery()
    ->getResult();
```

> Pagination support requires a compatible GraphQL dialect. See [Dialects](#dialects).

---

## Dialects

Dialects adapt the query generation and response parsing to a specific GraphQL API flavour.

### Available dialects

| Dialect          | Class                   | Use case                   |
|------------------|-------------------------|----------------------------|
| Default          | `DefaultDialect`        | Standard GraphQL APIs      |
| Data API Builder | `DataApiBuilderDialect` | Microsoft Data API Builder |

### Configure a dialect

```yaml
graphql_orm:
  dialect: GraphqlOrm\Dialect\DataApiBuilderDialect
```

### Data API Builder dialect

The `DataApiBuilderDialect` wraps results in an `items` envelope and supports cursor-based pagination with `hasNextPage`
and `endCursor`:

```graphql
query {
  tasks(first: 10) {
    items {
      id
      title
    }
    hasNextPage
    endCursor
  }
}
```

### Custom dialect

Implement `GraphqlQueryDialect` to support any API:

```php
use GraphqlOrm\Dialect\GraphqlQueryDialect;

final class MyDialect implements GraphqlQueryDialect
{
    public function extractCollection(array $data): array { ... }
    public function createWalker(): GraphqlWalkerInterface { ... }
    public function applyQueryOptions(array $arguments, QueryOptions $options): array { ... }
    public function applyFilter(?FilterExpressionInterface $filter): array { ... }
}
```

---

## Schema validation

GraphQL ORM can validate your entity mapping against the live GraphQL schema at boot time, catching mismatches before
they cause runtime errors.

### What is validated

- Every mapped entity has a corresponding GraphQL type in the schema
- Every mapped field exists on that type
- Scalar types are compatible between PHP and GraphQL (e.g. `int` ↔ `Int`, `string` ↔ `String`)

When a field is not found, the error message suggests the closest match:

```
[App\GraphQL\Entity\Task] Field "titel" (mapped from "titel") does not exist
on GraphQL type "Task". Did you mean "title"?
```

### Configuration

```yaml
graphql_orm:
  schema_validation:
    mode: exception # exception | warning | disabled
```

| Mode        | Behaviour                                                                         |
|-------------|-----------------------------------------------------------------------------------|
| `exception` | Throws `SchemaValidationException` on the first request, blocking the application |
| `warning`   | Logs violations as warnings without interrupting execution                        |
| `disabled`  | No validation (default)                                                           |

Entity classes are discovered automatically from `mapping.entity.dir` — no manual list needed.

> Schema validation requires the GraphQL API to be reachable at boot time. Use `disabled` in environments where the API
> may not be available (CI, offline dev, etc.).

---

## Logging

GraphQL ORM logs every query to a dedicated Monolog channel.

### Setup

Declare the channel in your Monolog configuration:

```yaml
# config/packages/monolog.yaml
monolog:
  channels:
    - graphql_orm
```

Logs are written to your environment's default log file (`var/log/dev.log`, etc.) at the following levels:

- `debug` — successful queries (endpoint, duration, hydrated entities, query string, caller)
- `error` — queries that returned GraphQL errors

### Example log entry

```
[debug] GraphQL query executed {
    "endpoint": "http://localhost:4000/graphql",
    "duration_ms": 42.3,
    "response_size": 1024,
    "hydrated_entities": 12,
    "hydrated_relations": 4,
    "caller": { "file": "src/Controller/HomeController.php", "line": 23 },
    "query": "query { tasks { id title } }"
}

[error] GraphQL query returned errors {
    "endpoint": "http://localhost:4000/graphql",
    "errors": [{ "message": "Field 'foo' does not exist" }],
    "query": "query { tasks { foo } }"
}
```

---

## Profiler

GraphQL ORM integrates with the **Symfony Web Debug Toolbar** and Profiler.

For each request, the panel shows:

- Number of GraphQL queries executed
- GraphQL errors (highlighted in red)
- Per-query details:
	- Endpoint
	- Duration (measured via Symfony Stopwatch)
	- Response size
	- Hydration stats (entities, relations, collections, max depth)
	- Generated GraphQL query (with Copy button)
	- Interactive AST viewer
	- Variables
	- Caller (file + line that triggered the query)

No additional configuration is needed — the collector is registered automatically when `symfony/stopwatch` is available.

---

## BeforeHydrate hook

The `#[BeforeHydrate]` attribute marks a method to be called just before the entity fields are assigned. The method
receives the **raw GraphQL data array**, allowing inspection or capture of fields that are not mapped (e.g.
`__typename`, pagination metadata, extra context).

### Basic usage

```php
use GraphqlOrm\Attribute\BeforeHydrate;
use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

#[GraphqlEntity(name: 'products', repositoryClass: ProductRepository::class)]
class Product
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'name')]
    public string $name;

    // Populated from raw data before hydration — not a mapped field
    public string $graphqlType = '';

    /**
     * @param array<string, mixed> $data
     */
    #[BeforeHydrate]
    public function onBeforeHydrate(array $data): void
    {
        $this->graphqlType = $data['__typename'] ?? 'unknown';
    }
}
```

### Rules

- The method must be **public** and accept a **single `array` parameter** (the raw data)
- Multiple `#[BeforeHydrate]` methods are supported on the same entity — all are called
- The hook always runs when the entity is first created — there is no partial hydration check (unlike `#[AfterHydrate]`)
- At the time the hook runs, no fields are hydrated yet — all typed properties without a default value are uninitialized

---

## AfterHydrate hook

The `#[AfterHydrate]` attribute marks a method to be called automatically after the entity has been fully hydrated. Use
it to compute virtual fields or run any post-hydration logic.

### Basic usage

```php
use GraphqlOrm\Attribute\AfterHydrate;
use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

#[GraphqlEntity(name: 'tasks', repositoryClass: TaskRepository::class)]
class Task
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'title')]
    public string $title;

    #[GraphqlField(mappedFrom: 'dueDate')]
    public ?\DateTimeImmutable $dueDate = null;

    // Virtual field — not in the GraphQL schema, computed after hydration
    public bool $isOverdue = false;

    #[AfterHydrate]
    public function compute(): void
    {
        $this->isOverdue = $this->dueDate !== null
            && $this->dueDate < new \DateTimeImmutable();
    }
}
```

### Rules

- The method must be **public** and take **no arguments**
- Multiple `#[AfterHydrate]` methods are supported on the same entity — all are called
- The hook is **skipped** if any mapped field (`#[GraphqlField]`) is not initialized, which happens when an entity is
  partially hydrated (e.g. a nested relation with only a subset of fields selected)
- Virtual fields (properties without `#[GraphqlField]`) are intentionally ignored in this check — they are expected to
  be populated by the hook itself

### Partial hydration

When a relation is loaded with only a subset of fields (e.g. `tasks { id }` inside a `user`), the `Task` entity is
hydrated without `title`, `dueDate`, etc. In this case, the hook is skipped to avoid accessing uninitialized properties:

```graphql
query {
  users {
    items {
      id
      name
      tasks {
        items {
          id         # only id selected — AfterHydrate on Task is skipped
        }
      }
    }
  }
}
```

---

## Using both hooks together

`#[BeforeHydrate]` and `#[AfterHydrate]` can coexist on the same entity. They always execute in order: **before →
hydration → after**.

```php
#[GraphqlEntity(name: 'products', repositoryClass: ProductRepository::class)]
class Product
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'price')]
    public float $price;

    #[GraphqlField(mappedFrom: 'taxRate')]
    public float $taxRate;

    public string $graphqlType = '';
    public float $priceWithTax = 0.0;

    /**
     * @param array<string, mixed> $data
     */
    #[BeforeHydrate]
    public function captureMetadata(array $data): void
    {
        // Runs before fields are assigned
        $this->graphqlType = $data['__typename'] ?? 'unknown';
    }

    #[AfterHydrate]
    public function computePrices(): void
    {
        // Runs after all fields are assigned
        $this->priceWithTax = $this->price * (1 + $this->taxRate / 100);
    }
}
```

| Hook               | Runs                    | Arguments                        | Use case                                |
|--------------------|-------------------------|----------------------------------|-----------------------------------------|
| `#[BeforeHydrate]` | Before field assignment | `array $data` (raw GraphQL data) | Capture unmapped fields, raw metadata   |
| `#[AfterHydrate]`  | After field assignment  | none                             | Compute virtual fields, post-processing |

---

## Console commands

### Generate an entity

```bash
php bin/console graphqlorm:make:entity Task
```

Launches an interactive wizard that generates:

- An entity class with `#[GraphqlEntity]` and `#[GraphqlField]` attributes
- A typed repository class

Supports scalar fields, nullable fields, object relations, and collection relations. Existing entities are suggested
with autocomplete when defining relations.

### Debug an entity

```bash
# Display resolved metadata for an entity (short name or FQCN)
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