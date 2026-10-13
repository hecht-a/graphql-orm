# Entity mapping

## Define an entity

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

## `#[GraphqlEntity]`

| Parameter         | Type                 | Description                                  |
|-------------------|----------------------|----------------------------------------------|
| `name`            | `string`             | GraphQL root query field name (e.g. `tasks`) |
| `repositoryClass` | `class-string\|null` | Associated repository class                  |

## `#[GraphqlField]`

| Parameter          | Type                 | Description                                            |
|--------------------|----------------------|--------------------------------------------------------|
| `mappedFrom`       | `string\|null`       | Field name in the GraphQL schema; named by the [naming strategy](#naming-strategy) when omitted |
| `identifier`       | `bool`               | Marks this field as the entity identifier              |
| `targetEntity`     | `class-string\|null` | Explicit relation target (optional if PHP type is set) |
| `ignoreValidation` | `bool`               | Ignore the schema validation                           |

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
    #[GraphqlField(identifier: true)]
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

Relations are detected automatically from the PHP property type:

```php
// Automatic detection via PHP type
#[GraphqlField(mappedFrom: 'user')]
public User $user;

// Explicit declaration
#[GraphqlField(mappedFrom: 'user', targetEntity: User::class)]
public mixed $user;

// Collection: the item type is read from the docblock (`Task[]`, `list<Task>`, `array<int, Task>`)
/** @var Task[] */
#[GraphqlField(mappedFrom: 'tasks')]
public array $tasks = [];

// Collection with an explicit target
#[GraphqlField(mappedFrom: 'tasks', targetEntity: Task::class)]
public array $tasks = [];
```

An `array` property without `targetEntity` and without a docblock pointing to a `#[GraphqlEntity]` class is a plain
array (e.g. a list of scalars such as `/** @var string[] */`). An untyped property is hydrated with the raw value.

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
