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
| `mappedFrom`       | `string`             | Field name in the GraphQL schema                       |
| `identifier`       | `bool`               | Marks this field as the entity identifier              |
| `targetEntity`     | `class-string\|null` | Explicit relation target (optional if PHP type is set) |
| `ignoreValidation` | `bool`               | Ignore the schema validation                           |

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

`DateTimeImmutable` fields are automatically cast from ISO 8601 strings or Unix timestamps (in milliseconds).
