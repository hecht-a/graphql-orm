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
| `query_builder` | `null`: `findAll()`                         | callable receiving the repository and returning a query builder     |
| `choice_label`  | `__toString()`, otherwise the identifier    | property path or callable, as with `ChoiceType`                     |
| `autocomplete`  | `false`                                     | search the entities as the user types (see [below](#autocomplete))  |
| `search_fields` | `null`                                      | fields searched by an autocomplete field, required with it          |

The other options are those of `ChoiceType` (`multiple`, `expanded`, `placeholder`...).

```php
$builder->add('task', GraphqlEntityType::class, [
    'class' => Task::class,
    'choice_label' => 'title',
    'query_builder' => fn (TaskRepository $repository) => $repository->createQueryBuilder()
        ->where($repository->createQueryBuilder()->expr()->eq('done', false))
        ->orderBy('title', Direction::Asc),
]);
```

The callable receives the repository of the class, as `GraphqlManager::getRepository()` gives it: your own repository
service when the entity names one in its `repositoryClass`, a generic `GraphqlEntityRepository` otherwise.

The choices are loaded once the form needs them (rendering or submission), with one query. On submission, only the
submitted entities are loaded, by their identifier, within the restriction of `query_builder`. With `choice_lazy` (a
`ChoiceType` option), the list itself is never loaded: only the selected entities are rendered. Mind the page size of
your API: Data API Builder returns 100 rows by default (`runtime.pagination.default-page-size`), so limit the choices
with `query_builder` or raise the page size.

## Autocomplete

For a choice among many entities, such as thousands of clients, `autocomplete` searches them as the user types, with
[Symfony UX Autocomplete](https://symfony.com/bundles/ux-autocomplete/current/index.html):

```bash
composer require symfony/ux-autocomplete
```

```yaml
# config/routes/graphql_orm.yaml
graphql_orm:
    resource: '@GraphqlOrmBundle/Resources/config/routes.php'
```

```php
$builder->add('client', GraphqlEntityType::class, [
    'class' => Client::class,
    'autocomplete' => true,
    'search_fields' => ['name', 'siren'],
    'choice_label' => 'name',
]);
```

The field sends what the user types to the endpoint of the bundle (`/_graphql_orm/autocomplete`), which answers with the
first entities whose `search_fields` contain it, as many as the `max_results` option of UX Autocomplete (10 by default,
20 when the option is not given, 100 at most); typing more narrows the results, which are not paginated. Only the
selected entity is loaded to render or submit the form (`choice_lazy` is on). The search queries every entity of the
class, without the `query_builder` restriction, and labels the results with `choice_label` when it is a property path,
with `__toString()` or the identifier otherwise.

The URL of the search is signed with the `UriSigner` of FrameworkBundle: the endpoint only queries the entity, the
fields and the label a form of the application asked for. Restrict the endpoint as the pages using it are, e.g. with an
`access_control` on `^/_graphql_orm/autocomplete`.

In the [profiler](profiler.md), the query of a search has the origin `autocomplete`, and the "Autocomplete" tab
describes the search: entity, fields searched, text typed (masked unless `redact_query_values` is false), results and
limit.
