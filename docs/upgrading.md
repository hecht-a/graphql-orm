# Upgrading from 4.x to 5.0

5.0 removes what 4.9 deprecated and changes four default values. The way is the same as for Symfony:

1. Upgrade to the last 4.x (`composer require hecht-a/graphql-orm:^4.10`) and run the application and its tests.
2. Fix every deprecation reported in the logs and the test output (`Since hecht-a/graphql-orm 4.9: ...`): each of them
   is a change of 5.0, described below.
3. Upgrade to 5.0: `composer require hecht-a/graphql-orm:^5.0`.

## Mapping attributes

Until 4.8, the attributes were `#[GraphqlEntity]` and `#[GraphqlField]` of `GraphqlOrm\Attribute`, and
`#[GraphqlField]` also marked the identifier and mapped the relations. 4.9 added the attributes of
`GraphqlOrm\Mapping` and deprecated the old ones, which 5.0 removes:

```php
// 4.8
use GraphqlOrm\Attribute\Fetch;
use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

#[GraphqlEntity(name: 'tasks')]
class Task
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'user', fetch: Fetch::Lazy)]
    public ?User $user = null;
}

// 4.9 and 5.0
use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Fetch;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

#[Entity(name: 'tasks')]
class Task
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Relation(mappedFrom: 'user', fetch: Fetch::Lazy)]
    public ?User $user = null;
}
```

| 4.8                                  | 4.9 and 5.0                            |
|--------------------------------------|----------------------------------------|
| `GraphqlOrm\Attribute\GraphqlEntity` | `GraphqlOrm\Mapping\Entity`            |
| `GraphqlOrm\Attribute\GraphqlField`  | `GraphqlOrm\Mapping\Field`             |
| `#[GraphqlField(identifier: true)]`  | `#[Field]` and `#[Identifier]`         |
| `#[GraphqlField]` on a relation      | `#[Relation]`, with the same arguments |
| `GraphqlOrm\Attribute\Fetch`         | `GraphqlOrm\Mapping\Fetch`             |

In 5.0, a class still mapped with the old attributes is no entity anymore: using it throws a
`NotAGraphqlEntityException`. `graphqlorm:make:entity` generates the new attributes.

The metadata being cached outside debug mode, 4.9 reports the deprecations of the mapping when the cache is built:
after `cache:clear`, or in the test environment. A class mapped with Doctrine too would have two `Entity` attributes:
import one namespace with an alias, as `use GraphqlOrm\Mapping as GraphqlOrm;` then `#[GraphqlOrm\Entity]`.

## Default values

| Option                   | 4.x        | 5.0                                                     |
|--------------------------|------------|---------------------------------------------------------|
| `identity_map`           | `false`    | `true`                                                  |
| `max_depth`              | `2`        | `1`                                                     |
| `variables.enabled`      | `false`    | `true`                                                  |
| `schema_validation.mode` | `disabled` | `warning` in the `dev` environment, `disabled` otherwise |

An application that set these options, as the deprecations of 4.9 asked, sees no change: it can keep them, or drop the
ones equal to the new defaults. Otherwise:

- **`identity_map`**: the queries of a request share their entities, and `find()` serves an entity known in full
  without a query. Set `false` to keep every query apart. See
  [shared identity map](configuration.md#shared-identity-map).
- **`max_depth`**: the automatic selection loads one level of relations; deeper relations only get their identifier.
  Raise `max_depth`, select the fields you need, or map the relation with `Fetch::Lazy` to load it on access. See
  [lazy loading](configuration.md#lazy-loading).
- **`variables.enabled`**: the root arguments are sent as typed GraphQL variables, their types coming from a cached
  introspection of the schema. An API refusing introspection gets the values inline, with a warning in the logs. Set
  `false` to send them inline without introspecting. See [variables](configuration.md#variables).
- **`schema_validation.mode`**: in the `dev` environment, the mapping is checked against the schema on the first
  request and the mismatches are logged. 4.9 announced "in debug mode": 5.0 relies on `kernel.environment` instead, so
  that a test suite, run in debug mode, does not introspect the API.

## Ordering directions

`Direction::ASC` and `Direction::DESC` are removed: use the cases `Direction::Asc` and `Direction::Desc`. The values
sent to the API (`ASC`, `DESC`) are unchanged.

## Final classes

`GraphqlManager` is final (`@final` since 4.9). Depend on `GraphqlManagerInterface`, as on Doctrine
`EntityManagerInterface`, to simulate or decorate it:

```php
use GraphqlOrm\GraphqlManagerInterface;

final class TaskReport
{
    public function __construct(
        private readonly GraphqlManagerInterface $manager,
    ) {
    }
}
```

`EntityHydrator` and `GraphqlEntityMetadataFactory`, internal, are final as well.

## Tests

`MockGraphqlServer` answers the introspection queries of the bundle (typed variables, schema validation) with an empty
schema, without recording them nor consuming the queued responses: the tests keep working with the new defaults. The
values are then sent inline, as without variables.

With Data API Builder, `count()` on an entity whose identifier is no number needs the schema to find the field to count
on: against `MockGraphqlServer`, it throws a `LogicException`. See [counting](repositories.md#counting).

## Dependencies

`symfony/deprecation-contracts` is no longer a direct dependency of the bundle. An application calling
`trigger_deprecation()` requires it itself, if no other package brings it.
