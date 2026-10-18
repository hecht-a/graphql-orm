# Entity mapping

## Define an entity

```php
use GraphqlOrm\Mapping as GraphqlOrm;

#[GraphqlOrm\Entity(name: 'tasks', repositoryClass: TaskRepository::class)]
class Task
{
    #[GraphqlOrm\Field(mappedFrom: 'id')]
    #[GraphqlOrm\Identifier]
    public int $id;

    #[GraphqlOrm\Field(mappedFrom: 'title')]
    public string $title;

    #[GraphqlOrm\Field(mappedFrom: 'dueDate')]
    public ?DateTimeImmutable $dueDate = null;

    #[GraphqlOrm\Relation(mappedFrom: 'user')]
    public ?User $user = null;
}
```

The attributes are imported through a namespace alias, as with Doctrine `use Doctrine\ORM\Mapping as ORM;`: a class
mapped with Doctrine too can hold both `Entity` attributes.

## `#[Entity]`

| Parameter         | Type                 | Description                                  |
|-------------------|----------------------|----------------------------------------------|
| `name`            | `string`             | GraphQL root query field name (e.g. `tasks`) |
| `repositoryClass` | `class-string\|null` | Associated repository class                  |
| `manager`         | `string\|null`       | The manager of its API, when not the one whose entity namespace holds it, see [several APIs](multiple-apis.md#the-manager-of-an-entity) |

`#[GraphqlOrm\Cache]` on the class keeps its entities in the [second-level cache](caching.md#second-level-cache).

## `#[Field]`

A field holding a value: a scalar, a date, an enum, or a value object given by a [type converter](#type-converters).

| Parameter          | Type                 | Description                                            |
|--------------------|----------------------|--------------------------------------------------------|
| `mappedFrom`       | `string\|null`       | Field name in the GraphQL schema; named by the [naming strategy](#naming-strategy) when omitted |
| `ignoreValidation` | `bool`               | Ignore the schema validation                           |
| `converter`        | `class-string\|null` | The [type converter](#type-converters) of the value, rather than the one of the type of the property |

## `#[Identifier]`

Marks the field identifying the entity, declared with `#[Field]`, as Doctrine `#[Id]`. `find()`, the identity
map, the lazy relations and the form type need it. An entity has one at most: a second one throws a
`TooManyIdentifiersException`, and `#[Identifier]` without `#[Field]` an `InvalidArgumentException`.

```php
#[GraphqlOrm\Field(mappedFrom: 'id')]
#[GraphqlOrm\Identifier]
public int $id;
```

## `#[Relation]`

A relation to another entity, or a collection of them, as Doctrine `#[ManyToOne]` and `#[OneToMany]`: it replaces
`#[Field]` on the property.

| Parameter          | Type                 | Description                                            |
|--------------------|----------------------|--------------------------------------------------------|
| `mappedFrom`       | `string\|null`       | Field name in the GraphQL schema; named by the [naming strategy](#naming-strategy) when omitted |
| `targetEntity`     | `class-string\|null` | The related entity, when the type of the property does not tell it |
| `fetch`            | `Fetch`              | `Fetch::Eager` (default) or `Fetch::Lazy`, see [lazy relations](#lazy-relations) |
| `ignoreValidation` | `bool`               | Ignore the schema validation                           |

A property mapped with both `#[Field]` and `#[Relation]`, a `#[Relation]` whose type is no entity, or a `#[Field]`
holding an entity, throws an `InvalidArgumentException`.

## Lazy relations

A relation is selected with its entity, up to `max_depth`. One read rarely, or heavy, can be fetched lazily, as with
Doctrine `fetch: LAZY`:

```php
use GraphqlOrm\Mapping as GraphqlOrm;

#[GraphqlOrm\Relation(mappedFrom: 'user', fetch: GraphqlOrm\Fetch::Lazy)]
public ?User $user = null;

/** @var Task[] */
#[GraphqlOrm\Relation(mappedFrom: 'tasks', fetch: GraphqlOrm\Fetch::Lazy)]
public array $tasks = [];
```

- The relation is selected by its identifier only (`user { id }`), whatever `max_depth`, and hydrated as a PHP lazy
  ghost: reading its identifier costs nothing, the first access to another property loads it.
- The ghosts of a response are loaded together: one query for the users of all the tasks, when the dialect filters on
  a list (Data API Builder, Hasura).
- A collection is selected by the identifiers of its items, each item being a ghost: a PHP array cannot be lazy itself.
- An explicit `select('user.name')`, or `with('user')`, still loads the relation with the query.
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
#[GraphqlOrm\Entity(name: 'tasks')]
class Task
{
    #[GraphqlOrm\Field]
    #[GraphqlOrm\Identifier]
    public int $id;

    #[GraphqlOrm\Field] // due_date
    public ?DateTimeImmutable $dueDate = null;

    #[GraphqlOrm\Field(mappedFrom: 'deadline_at')]
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
#[GraphqlOrm\Relation(mappedFrom: 'user')]
public User $user;

// Explicit declaration
#[GraphqlOrm\Relation(mappedFrom: 'user', targetEntity: User::class)]
public mixed $user;

// Collection: the item type is read from the docblock (`Task[]`, `list<Task>`, `array<int, Task>`)
/** @var Task[] */
#[GraphqlOrm\Relation(mappedFrom: 'tasks')]
public array $tasks = [];

// Collection with an explicit target
#[GraphqlOrm\Relation(mappedFrom: 'tasks', targetEntity: Task::class)]
public array $tasks = [];
```

### Collections of doctrine/collections

With `doctrine/collections` installed, a collection can be typed `Collection` (or `ReadableCollection`,
`ArrayCollection`): it is hydrated as an `ArrayCollection`, its item type read from the generic type of the docblock.
The `array` properties stay arrays.

```php
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

/** @var Collection<int, Task> */
#[GraphqlOrm\Relation]
public Collection $tasks;

$project->tasks->filter(fn (Task $task) => !$task->done)->count();
```

It works with `Fetch::Lazy` as an array does, and the [serializer](serializer.md) writes it as a list. The results of
`findBy()` and of the query builder stay arrays, as with Doctrine.

An `array` mapped with `#[Field]` is a plain array (e.g. a list of scalars such as `/** @var string[] */`). An
untyped property is hydrated with the raw value.

## Upgrading from the mapping of 4.8

The attributes `#[GraphqlEntity]` and `#[GraphqlField]` of 4.8 are removed in 5.0: see
[upgrading from 4.x](upgrading.md#mapping-attributes) for their equivalents.

## Supported PHP types

| PHP type            | GraphQL scalars                                                                   |
|---------------------|-----------------------------------------------------------------------------------|
| `int`               | `Int`, `ID`, `Byte`, `Short`, `Long`, `bigint`, `smallint`, `integer`             |
| `float`             | `Float`, `Single`, `Decimal`, `numeric`, `float8`, `float4`                       |
| `string`            | `String`, `ID`, `Time`, `JSON`, `UUID`, `uuid`, `text`, and the date scalars      |
| `bool`              | `Boolean`                                                                         |
| `DateTimeImmutable` | `DateTime`, `Datetime`, `Date`, `timestamptz`, `timestamp`, `date`, `Time`, `String` |
| `DateTime`, `DateTimeInterface` | Same as `DateTimeImmutable` (`DateTimeInterface` gets a `DateTimeImmutable`) |
| `BackedEnum`        | Built with `::from()`; an unknown value throws `CastException`                    |

The lowercase scalars are those of Hasura and Data API Builder. The [schema validation](schema-validation.md) checks the
scalars of the `int`, `float`, `string`, `bool` and `DateTimeImmutable` properties.

Date fields are automatically cast from ISO 8601 strings or Unix timestamps (in milliseconds); a value that is no date
throws a `CastException`.

## Type converters

A type converter turns a scalar of the API into a value object, `Money`, `Email`, `Siret`, and back. It implements
`TypeConverterInterface`, and `#[AsTypeConverter]` names the class it handles:

```php
use GraphqlOrm\Attribute\AsTypeConverter;
use GraphqlOrm\Converter\TypeConverterInterface;

#[AsTypeConverter(Money::class)]
final class MoneyConverter implements TypeConverterInterface
{
    // An Int of the API, in cents
    public function toPhp(mixed $value): Money
    {
        return Money::ofCents((int) $value);
    }

    public function toGraphql(mixed $value): int
    {
        return $value->cents;
    }
}
```

In Symfony, a service implementing `TypeConverterInterface` is registered by autoconfiguration, and every manager uses
it. Each property typed with its class is then converted, unless its `#[Field]` names another converter:

```php
#[GraphqlOrm\Field]
public ?Money $price = null;          // MoneyConverter, by its type

#[GraphqlOrm\Field(converter: DecimalMoneyConverter::class)]
public ?Money $discount = null;       // a Float of the API, as 12.5
```

- The values of the response are converted when the entity is hydrated, from the API or the second-level cache, and
  for the parameters of a [DTO](query-builder.md#dto-results) typed with the class (the converter the entity names for
  the field applies to a parameter of the same type).
- The value objects of the filters, `where()` and `findBy()`, `count()` and the aggregates, are converted back, inline
  or as variables, lists of `in()` included: `$qb->expr()->eq('price', Money::ofCents(1250))` sends
  `price: { eq: 1250 }`. So is the value keying a result with `indexBy`.
- `null` is never given to a converter: it stays `null`.
- `#[AsTypeConverter(Money::class, scalars: ['Int'])]` tells the GraphQL scalars the converter reads and writes: the
  [schema validation](schema-validation.md) checks the fields it converts use one of them. It reports as well a
  property typed with a value object that no converter converts.
- A converter named by `#[Field]` without dependencies needs no registration; one needing arguments must be a service,
  or an `InvalidArgumentException` is thrown when it is used.
- A converter receives the value of one field: a value object spread over several fields (an amount and its currency)
  is an [embedded value object](#embedded-value-objects).
- Without Symfony, the converters are given to the manager: `new GraphqlManager(..., typeConverters: new
  TypeConverterRegistry([new MoneyConverter()]))`.

## Embedded value objects

A value object spread over several fields, as the embeddables of Doctrine: the class is marked `#[Embeddable]`, its
fields mapped with `#[Field]`, and the property holding it with `#[Embedded]`.

```php
use GraphqlOrm\Mapping as GraphqlOrm;

#[GraphqlOrm\Embeddable]
final readonly class Money
{
    public function __construct(
        #[GraphqlOrm\Field] public int $amount,
        #[GraphqlOrm\Field] public string $currency,
    ) {
    }
}

#[GraphqlOrm\Entity(name: 'orders')]
class Order
{
    // A GraphQL object: price { amount currency }
    #[GraphqlOrm\Embedded]
    public ?Money $price = null;

    // Fields of the entity itself: total_amount, total_currency
    #[GraphqlOrm\Embedded(prefix: 'total_')]
    public ?Money $total = null;
}
```

| `#[Embedded]` | Description |
|---------------|-------------|
| `mappedFrom`  | GraphQL name of the object; named by the [naming strategy](#naming-strategy) when omitted |
| `prefix`      | the prefix of the fields of the entity holding the value object; a GraphQL object when omitted |

- The value object is created through its constructor, the values given by the name of their property: it can be
  `readonly`. Its fields follow the naming strategy, and take [type converters](#type-converters).
- A GraphQL object `null`, or prefixed fields all `null`, give `null`. A selection lacking a field the constructor
  needs leaves the property not selected, as a [partial entity](query-builder.md#field-selection).
- Its fields are named by their path: `select('price.amount')`, `where($qb->expr()->gte('total.amount', 100))`
  (`price: { amount: … }` or `total_amount`). The ordering and the criteria of the repositories, which the dialects
  give for fields of the entity, name the fields of prefixed value objects only: `findBy(['total.currency' =>
  'EUR'], ['total.amount' => Direction::Desc])`.
- It is kept in the second-level cache with its entity, and hydrated as well for a parameter of a
  [DTO](query-builder.md#dto-results) typed with it, or mapped to one of its fields with
  `#[GraphqlOrm\MapFrom('price.amount')]`.
- The schema validation checks its fields, the profiler lists them in the "Entities" tab, and the PHPStan rules accept
  their paths.
- An embedded value object holds no relation nor other embedded value object; a filter compares its fields, not the
  value object as a whole.
- `#[Embedded]` with `#[Field]`, `#[Relation]` or `#[Identifier]`, or on a property whose class has no `#[Embeddable]`,
  throws an `InvalidArgumentException`.
