# Writing entities

The manager writes the entities as Doctrine does: `persist()` a new entity, `remove()` one, change the properties of an
entity read by a query, then `flush()` sends the mutations.

```php
use GraphqlOrm\GraphqlManagerInterface;

public function __construct(private GraphqlManagerInterface $manager) {}

$task = new Task();
$task->title = 'Write the docs';
$task->user = $this->manager->getRepository(User::class)->find(1);
$this->manager->persist($task);

$done = $this->manager->getRepository(Task::class)->find(3);
$done->done = true; // no persist(): its changes are found by flush()

$this->manager->remove($this->manager->getRepository(Task::class)->find(4));

$this->manager->flush();

$task->id; // the identifier given by the API
```

The dialect names the mutations: `DataApiBuilderDialect`, `PostGraphileDialect` and `HasuraDialect` write the entities,
see [the mutations of each dialect](#the-mutations-of-each-dialect). With `DefaultDialect` or `RelayDialect`, which
know no mutation convention, `flush()` throws a `LogicException` when there is something to write.

## What is written

- **The entities persisted** are created with every field they set, their identifier left out when it is `null` (given
  by the API, then set on the entity).
- **The entities read by a query** are updated with the fields changed since. The values a query read are recorded as
  the API has them: only the fields that differ are sent, and **never a field the query did not select**: an entity
  read with `select('title')` only ever updates its title. An entity met by several queries knows the fields of each.
  A lazy entity not loaded yet has nothing to update.
- **The entities removed** are deleted. `remove()` an entity persisted and not flushed yet only forgets it; an entity
  no query read throws an `InvalidArgumentException`.

The DTOs of `hydrateAs()` are not entities: they are never written. `clear()` forgets the entities to write, as between
two requests.

## One document

`flush()` sends the mutations in one document, one aliased field each, which the API runs in order:

```graphql
mutation {
  m0: createTask(item: { title: "Write the docs", user_id: 1 }) { id }
  m1: updateTask(id: 3, item: { done: true }) { id }
  m2: deleteTask(id: 4) { id }
}
```

The entities created come first, then those updated, then those deleted. When a new entity relates to another new
entity, the other one is created by a document of its own first: its identifier is then known.

```php
$user = new User();
$user->name = 'User A';
$task = new Task();
$task->title = 'Write the docs';
$task->user = $user;

$this->manager->persist($user);
$this->manager->persist($task);
$this->manager->flush(); // createUser, then createTask with the identifier of the user
```

A new entity held by a relation and not persisted throws a `LogicException`: call `persist()` on it as well, as with
Doctrine without cascade.

The values are written inline in the document, converted as the filters are: a
[type converter](entity-mapping.md#type-converters) gives the value of a value object, an enum gives its value and a
date its ISO 8601 string. The logs and the profiler mask them unless `redact_query_values` is false.

## Relations

A relation is written through the field holding the identifier of the related entity, which the API names apart from
the relation: `user_id` for the relation `user`. Name it with `joinField`:

```php
#[GraphqlOrm\Relation(mappedFrom: 'user', joinField: 'user_id')]
public ?User $user = null;
```

A relation without `joinField` is read as before, but writing it throws a `LogicException`. A collection is written by
the entities it holds, as the inverse side of a Doctrine relation: `$task->user`, not `$user->tasks`.

## Failures

A GraphQL API has no transaction: the mutations applied before a failure stay applied. `flush()` throws a
`FlushException` telling how many were applied and the error of each one that failed:

```
1 of the 2 mutations were applied. Failed:
- createTask (App\Entity\Task): The title is too long.
```

The mutations applied are recorded: the next `flush()` only sends again those that failed, once the entities are
fixed. The documents that follow a failure are not sent.

## The mutations of each dialect

The mutations are named after the GraphQL type of the entity, given by `type` on `#[GraphqlOrm\Entity]`, the short name
of the class by default, and after its root field (`name`) for Hasura:

| Dialect                 | Create                              | Update                                                    | Delete                          |
|-------------------------|-------------------------------------|-----------------------------------------------------------|---------------------------------|
| `DataApiBuilderDialect` | `createTask(item: {...})`           | `updateTask(id: 3, item: {...})`                          | `deleteTask(id: 3)`             |
| `PostGraphileDialect`   | `createTask(input: { task: {...} })` | `updateTaskById(input: { id: 3, taskPatch: {...} })`     | `deleteTaskById(input: { id: 3 })` |
| `HasuraDialect`         | `insert_tasks_one(object: {...})`   | `update_tasks_by_pk(pk_columns: { id: 3 }, _set: {...})`  | `delete_tasks_by_pk(id: 3)`     |

```php
#[GraphqlOrm\Entity(name: 'allTasks', type: 'Task')]
```

Data API Builder needs the `create`, `update` and `delete` actions in the permissions of the entity, and PostGraphile
its default mutations (without `--disable-default-mutations`).

Another API is written with a dialect implementing `MutationDialect`, see [custom dialect](dialects.md#custom-dialect).

## Profiler

The profiler lists the mutations as the other queries, their origin `flush`, in their own color on the timeline.
