# Schema validation

GraphQL ORM can validate your entity mapping against the live GraphQL schema, catching mismatches before they cause
runtime errors.

## Validate in CI (recommended)

```bash
php bin/console graphqlorm:schema:validate
```

The command introspects the schema, lists every violation and exits with a non-zero code when the mapping does not
match or when the schema cannot be introspected. With [several APIs](multiple-apis.md), it validates each of them, or
the one named with `--manager=crm`. Run it in CI against an environment where the API is reachable.

## What is validated

- Every mapped entity has a corresponding GraphQL type in the schema. The type is the one returned by the root query
  field (`#[GraphqlOrm\Entity(name: ...)]`); for a connection (Data API Builder `items`, Relay `edges { node }`), the
  type of its entities is used. If the root field is not found, the type name is guessed from the root name (`tasks` → `Task`)
- Every mapped field exists on that type
- Scalar types are compatible between PHP and GraphQL (e.g. `int` ↔ `Int`, `string` ↔ `String`, `\DateTimeImmutable`
  ↔ `DateTime` or `timestamptz`)
- A property typed with a value object has a [type converter](entity-mapping.md#type-converters), and the field uses
  one of the scalars the converter tells with `#[AsTypeConverter(Money::class, scalars: ['Int'])]`, if it tells them;
  a converted field skips the comparison of the PHP and GraphQL types
- A field the schema declares nullable is mapped on a property accepting `null`: hydrating `null` into a `string`
  property fails with a `TypeError`, as soon as the API returns it
- A relation returns the GraphQL type of its target entity, as a list or connection for a collection (`array`
  property) and as a single object otherwise; a relation mapped on a scalar is reported too

A field or relation that the schema cannot describe is skipped with `ignoreValidation: true`, e.g.
`#[GraphqlOrm\Field(ignoreValidation: true)]`.

When a field is not found, the error message suggests the closest match:

```
[App\GraphQL\Entity\Task] Field "titel" (mapped from "titel") does not exist
on GraphQL type "Task". Did you mean "title"?
```

The other checks say how to fix the mapping:

```
[App\GraphQL\Entity\Task] Field "dueDate" is nullable in the schema but property "dueDate"
does not accept null: declare it as "?DateTimeImmutable".
[App\GraphQL\Entity\Task] Field "user" is a collection but returns a single object in the schema:
map it on a single relation.
```

## Validate on the first request

As an alternative to the command, validation can run on the first HTTP request handled by each PHP process:

```yaml
graphql_orm:
    schema_validation:
        mode: exception # exception | warning | disabled
```

| Mode        | Behaviour                                                                         |
|-------------|-----------------------------------------------------------------------------------|
| `exception` | Throws `SchemaValidationException` on the first request of each PHP process (the API being unreachable is also a failure) |
| `warning`   | Logs violations as warnings without interrupting execution (default in the dev environment) |
| `disabled`  | No validation (default in the other environments, tests included)                 |

Entity classes are discovered automatically from `mapping.entity.dir` (the classes directly in it, not in its
subdirectories) — no manual list needed.

The outcome is cached with the other introspections, in `variables.cache_pool` for `variables.ttl` seconds: when each
request runs a container of its own, as PHP does without a worker, the schema is not introspected on every request.
Editing an entity checks the mapping again at once; a change of the schema of the API is seen once the cache expires
(`cache:pool:clear cache.app`, the default pool, to see it sooner). The outcome is logged when the mapping is checked,
and shown on every request in the "Configuration" tab of the profiler.

> Schema validation on the first request requires the GraphQL API to be reachable then: in `exception` mode an
> unreachable API fails the request, in `warning` mode it is logged as a warning. Use `disabled` in environments where
> the API may not be available (CI, offline dev, etc.).
