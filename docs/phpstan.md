# PHPStan rules

The bundle ships PHPStan rules checking, at analysis time, the field names given to the repositories and the query
builder against the entity mapping: a typo is reported in your editor or your CI instead of as an API error at
runtime.

## Installation

With [phpstan/extension-installer](https://github.com/phpstan/extension-installer), the rules are enabled
automatically. Otherwise, include them in your PHPStan configuration:

```neon
includes:
    - vendor/hecht-a/graphql-orm/extension.neon
```

The entity classes must be loadable by PHPStan (autoloaded by Composer), as their attributes are read the same way
the bundle reads them at runtime.

## What is checked

```php
/** @var TaskRepository $tasks extends GraphqlEntityRepository<Task> */
$tasks->findBy(['titel' => 'A']);
// Field "titel" is not mapped on App\GraphQL\Entity\Task. Did you mean "title"?

$tasks->createQueryBuilder()->select('title.length');
// Field "title" of App\GraphQL\Entity\Task is not a relation: "title.length" cannot be selected.
```

| Call                                         | Keys or arguments checked | Expected name                  |
|----------------------------------------------|---------------------------|--------------------------------|
| `findBy()`, `findOneBy()`, `count()`         | criteria and ordering keys | GraphQL or PHP property name  |
| `GraphqlQueryBuilder::orderBy()`             | field                      | GraphQL or PHP property name  |
| `GraphqlQueryBuilder::select()`, `addSelect()` | paths, segment by segment | GraphQL or PHP property name |

Only constant values are checked: a field name computed at runtime is not. The entity is taken from the generic type
of the repository or query builder, so repositories must declare it (`@extends GraphqlEntityRepository<Task>`, as
the generated ones do).

## Fields that the entity does not map

Criteria and ordering keys are sent as is to the API, which may accept fields the entity does not map (a foreign key
such as `user_id`, for instance). They are reported by default, as they are most often typos. To allow one, ignore
it:

```php
$tasks->findBy(['user_id' => $userId]); // @phpstan-ignore graphqlOrm.unmappedField
```

To allow all of them, and only report what is certainly wrong (a path through a field that is not a relation):

```neon
parameters:
    graphqlOrm:
        reportUnmappedFields: false
```

## Naming strategy

The rules read the entities as the bundle does. When the bundle names the fields with a
[naming strategy](entity-mapping.md#naming-strategy) other than the default one, set the same class, which must be
buildable without argument:

```neon
parameters:
    graphqlOrm:
        namingStrategy: GraphqlOrm\Metadata\Naming\SnakeCaseNamingStrategy
```

## Error identifiers

| Identifier                 | Meaning                                                    |
|----------------------------|------------------------------------------------------------|
| `graphqlOrm.unmappedField` | field not mapped on the entity (`reportUnmappedFields`)    |
| `graphqlOrm.notARelation`  | path going through a field that is not a relation          |
