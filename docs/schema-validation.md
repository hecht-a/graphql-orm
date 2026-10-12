# Schema validation

GraphQL ORM can validate your entity mapping against the live GraphQL schema, catching mismatches before they cause
runtime errors.

## Validate in CI (recommended)

```bash
php bin/console graphqlorm:schema:validate
```

The command introspects the schema, lists every violation and exits with a non-zero code when the mapping does not
match or when the schema cannot be introspected. Run it in CI against an environment where the API is reachable.

## What is validated

- Every mapped entity has a corresponding GraphQL type in the schema. The type is the one returned by the root query
  field (`#[GraphqlEntity(name: ...)]`); for a paginated root field exposing an `items` list (e.g. Data API Builder
  connections), the type of `items` is used. If the root field is not found, the type name is guessed from the root
  name (`tasks` → `Task`)
- Every mapped field exists on that type
- Scalar types are compatible between PHP and GraphQL (e.g. `int` ↔ `Int`, `string` ↔ `String`)

When a field is not found, the error message suggests the closest match:

```
[App\GraphQL\Entity\Task] Field "titel" (mapped from "titel") does not exist
on GraphQL type "Task". Did you mean "title"?
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
| `warning`   | Logs violations as warnings without interrupting execution                        |
| `disabled`  | No validation (default)                                                           |

Entity classes are discovered automatically from `mapping.entity.dir` — no manual list needed.

> Schema validation requires the GraphQL API to be reachable at boot time. Use `disabled` in environments where the API
> may not be available (CI, offline dev, etc.).
