# Forms

`GraphqlEntityType` is a form field whose choices are entities, as Doctrine `EntityType`. It needs
[symfony/form](https://symfony.com/doc/current/forms.html), and is registered when the component is installed:

```php
use GraphqlOrm\Form\GraphqlEntityType;

$builder->add('assignee', GraphqlEntityType::class, [
    'class' => User::class,
    'choice_label' => 'name',
]);
```

The submitted values are the identifiers of the entities, and the form data is the entity chosen (an array of entities
with `'multiple' => true`). The entity given as initial data is selected by its identifier, even when it comes from
another query.

## Options

| Option          | Default                                     | Description                                                         |
|-----------------|---------------------------------------------|---------------------------------------------------------------------|
| `class`         | required                                    | class of the entities                                               |
| `query_builder` | `null`: `findAll()`                         | callable receiving a repository and returning a query builder       |
| `choice_label`  | `__toString()`, otherwise the identifier    | property path or callable, as with `ChoiceType`                     |

The other options are those of `ChoiceType` (`multiple`, `expanded`, `placeholder`...).

```php
$builder->add('task', GraphqlEntityType::class, [
    'class' => Task::class,
    'choice_label' => 'title',
    'query_builder' => fn (GraphqlEntityRepository $repository) => $repository->createQueryBuilder()
        ->where($repository->createQueryBuilder()->expr()->eq('done', false))
        ->orderBy('title', Direction::ASC),
]);
```

The callable receives a `GraphqlEntityRepository` of the class, not your own repository service: build the query from
its query builder.

The choices are loaded once the form needs them (rendering or submission), with one query. Mind the page size of your
API: Data API Builder returns 100 rows by default (`runtime.pagination.default-page-size`), so limit the choices with `query_builder` or raise the page size.
