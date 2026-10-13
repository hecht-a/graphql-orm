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
$tasks->findBy(['dueDate' => null]);
// Field "dueDate" is the PHP property of App\GraphQL\Entity\Task: use its GraphQL name "due_date".

$tasks->findBy(['titel' => 'A']);
// Field "titel" is not mapped on App\GraphQL\Entity\Task. Did you mean "title"?

$tasks->createQueryBuilder()->select('title.length');
// Field "title" of App\GraphQL\Entity\Task is not a relation: "title.length" cannot be selected.
```

| Call                                         | Keys or arguments checked | Expected name                  |
|----------------------------------------------|---------------------------|--------------------------------|
| `findBy()`, `findOneBy()`, `count()`         | criteria and ordering keys | GraphQL name (`mappedFrom`)   |
| `GraphqlQueryBuilder::orderBy()`             | field                      | GraphQL name (`mappedFrom`)   |
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

To allow all of them, and only report what is certainly wrong (a PHP property name instead of a GraphQL name, a path
through a field that is not a relation):

```neon
parameters:
    graphqlOrm:
        reportUnmappedFields: false
```

## Error identifiers

| Identifier                 | Meaning                                                    |
|----------------------------|------------------------------------------------------------|
| `graphqlOrm.propertyName`  | PHP property name used where the GraphQL name is sent      |
| `graphqlOrm.unmappedField` | field not mapped on the entity (`reportUnmappedFields`)    |
| `graphqlOrm.notARelation`  | path going through a field that is not a relation          |
