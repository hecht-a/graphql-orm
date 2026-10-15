# Entity mapping

## Define an entity

```php
use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Attribute\Identifier;
use GraphqlOrm\Attribute\Relation;

#[GraphqlEntity(name: 'tasks', repositoryClass: TaskRepository::class)]
class Task
{
    #[GraphqlField(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[GraphqlField(mappedFrom: 'title')]
    public string $title;

    #[GraphqlField(mappedFrom: 'dueDate')]
    public ?DateTimeImmutable $dueDate = null;

    #[Relation(mappedFrom: 'user')]
    public ?User $user = null;
}
```

## `#[GraphqlEntity]`

| Parameter         | Type                 | Description                                  |
|-------------------|----------------------|----------------------------------------------|
| `name`            | `string`             | GraphQL root query field name (e.g. `tasks`) |
| `repositoryClass` | `class-string\|null` | Associated repository class                  |

## `#[GraphqlField]`

A field holding a value: a scalar, a date, an enum.

| Parameter          | Type                 | Description                                            |
|--------------------|----------------------|--------------------------------------------------------|
| `mappedFrom`       | `string\|null`       | Field name in the GraphQL schema; named by the [naming strategy](#naming-strategy) when omitted |
| `ignoreValidation` | `bool`               | Ignore the schema validation                           |

## `#[Identifier]`

Marks the field identifying the entity, declared with `#[GraphqlField]`, as Doctrine `#[Id]`. `find()`, the identity
map, the lazy relations and the form type need it. An entity has one at most, and `#[Identifier]` without
`#[GraphqlField]` throws an `InvalidArgumentException`.

```php
#[GraphqlField(mappedFrom: 'id')]
#[Identifier]
public int $id;
```

## `#[Relation]`

A relation to another entity, or a collection of them, as Doctrine `#[ManyToOne]` and `#[OneToMany]`: it replaces
`#[GraphqlField]` on the property.

| Parameter          | Type                 | Description                                            |
|--------------------|----------------------|--------------------------------------------------------|
| `mappedFrom`       | `string\|null`       | Field name in the GraphQL schema; named by the [naming strategy](#naming-strategy) when omitted |
| `targetEntity`     | `class-string\|null` | The related entity, when the type of the property does not tell it |
| `fetch`            | `Fetch`              | `Fetch::Eager` (default) or `Fetch::Lazy`, see [lazy relations](#lazy-relations) |
| `ignoreValidation` | `bool`               | Ignore the schema validation                           |

A property mapped with both `#[GraphqlField]` and `#[Relation]`, or a `#[Relation]` whose type is no entity, throws an
`InvalidArgumentException`.

## Lazy relations

A relation is selected with its entity, up to `max_depth`. One read rarely, or heavy, can be fetched lazily, as with
Doctrine `fetch: LAZY`:

```php
use GraphqlOrm\Attribute\Fetch;

#[Relation(mappedFrom: 'user', fetch: Fetch::Lazy)]
public ?User $user = null;

/** @var Task[] */
#[Relation(mappedFrom: 'tasks', fetch: Fetch::Lazy)]
public array $tasks = [];
```

- The relation is selected by its identifier only (`user { id }`), whatever `max_depth`, and hydrated as a PHP lazy
  ghost: reading its identifier costs nothing, the first access to another property loads it.
- The ghosts of a response are loaded together: one query for the users of all the tasks, when the dialect filters on
  a list (Data API Builder, Hasura).
- A collection is selected by the identifiers of its items, each item being a ghost: a PHP array cannot be lazy itself.
- An explicit `select('user.name')` still loads the relation with the query.
- It needs no `lazy_loading`, which only applies to the relations beyond `max_depth`.

## Naming strategy

`mappedFrom` can be left out: the field is then named from its property by the naming strategy of the bundle, as
Doctrine `naming_strategy` does for columns. An explicit `mappedFrom` always wins.

| Strategy                  | `dueDate` is named | Suits                                              |
|---------------------------|--------------------|----------------------------------------------------|
| `DefaultNamingStrategy`   | `dueDate`          | camelCase APIs (PostGraphile, most GraphQL APIs)   |
| `SnakeCaseNamingStrategy` | `due_date`         | APIs exposing database columns (Hasura, Data API Builder) |

```yaml
# config/packages/graphql_orm.yaml
graphql_orm:
    naming_strategy: GraphqlOrm\Metadata\Naming\SnakeCaseNamingStrategy
```

```php
#[GraphqlEntity(name: 'tasks')]
class Task
{
    #[GraphqlField]
    #[Identifier]
    public int $id;

    #[GraphqlField] // due_date
    public ?DateTimeImmutable $dueDate = null;

    #[GraphqlField(mappedFrom: 'deadline_at')]
    public ?DateTimeImmutable $deadline = null;
}
```

`naming_strategy` is a service id: implement `NamingStrategy` for another convention. With the
[PHPStan rules](phpstan.md), set the same class in the `graphqlOrm.namingStrategy` parameter. After changing the
strategy, clear the cache (`cache:clear`), which holds the metadata outside debug mode.

## Relations

The related entity is read from the PHP type of the property:

```php
// From the type
#[Relation(mappedFrom: 'user')]
public User $user;

// Explicit declaration
#[Relation(mappedFrom: 'user', targetEntity: User::class)]
public mixed $user;

// Collection: the item type is read from the docblock (`Task[]`, `list<Task>`, `array<int, Task>`)
/** @var Task[] */
#[Relation(mappedFrom: 'tasks')]
public array $tasks = [];

// Collection with an explicit target
#[Relation(mappedFrom: 'tasks', targetEntity: Task::class)]
public array $tasks = [];
```

An `array` mapped with `#[GraphqlField]` is a plain array (e.g. a list of scalars such as `/** @var string[] */`). An
untyped property is hydrated with the raw value.

## Upgrading from the mapping of 4.8

Until 4.8, `#[GraphqlField]` also marked the identifier and mapped the relations. This still works in 4.x, with a
deprecation reported in the logs and the tests (`Since hecht-a/graphql-orm 4.9: ...`), and is removed in 5.0:

```php
// 4.8                                              // 4.9
#[GraphqlField(mappedFrom: 'id', identifier: true)] #[GraphqlField(mappedFrom: 'id')]
public int $id;                                     #[Identifier]
                                                    public int $id;

#[GraphqlField(mappedFrom: 'user', fetch: Fetch::Lazy)]  #[Relation(mappedFrom: 'user', fetch: Fetch::Lazy)]
public ?User $user = null;                               public ?User $user = null;
```

The arguments of a relation (`mappedFrom`, `targetEntity`, `fetch`, `ignoreValidation`) move as they are.
`graphqlorm:make:entity` generates the new attributes. The metadata being cached outside debug mode, a deprecation is
reported when the cache is built: after `cache:clear`, or in the test environment.

## Supported PHP types

| PHP type            | GraphQL scalars                                            |
|---------------------|------------------------------------------------------------|
| `int`               | `Int`, `ID`, `Byte`, `Short`, `Long`                       |
| `float`             | `Float`, `Single`, `Decimal`                               |
| `string`            | `String`, `ID`, `Date`, `DateTime`, `Time`, `JSON`, `UUID` |
| `bool`              | `Boolean`                                                  |
| `DateTimeImmutable` | `DateTime`, `Date`, `Time`, `String`                       |
| `DateTime`, `DateTimeInterface` | Same as `DateTimeImmutable` (`DateTimeInterface` gets a `DateTimeImmutable`) |
| `BackedEnum`        | Built with `::from()`; an unknown value throws `CastException` |

Date fields are automatically cast from ISO 8601 strings or Unix timestamps (in milliseconds).
