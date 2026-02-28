# Schema validation

GraphQL ORM can validate your entity mapping against the live GraphQL schema at boot time, catching mismatches before
they cause runtime errors.

## What is validated

- Every mapped entity has a corresponding GraphQL type in the schema
- Every mapped field exists on that type
- Scalar types are compatible between PHP and GraphQL (e.g. `int` ↔ `Int`, `string` ↔ `String`)

When a field is not found, the error message suggests the closest match:

```
[App\GraphQL\Entity\Task] Field "titel" (mapped from "titel") does not exist
on GraphQL type "Task". Did you mean "title"?
```

## Configuration

```yaml
graphql_orm:
    schema_validation:
        mode: exception # exception | warning | disabled
```

| Mode        | Behaviour                                                                         |
|-------------|-----------------------------------------------------------------------------------|
| `exception` | Throws `SchemaValidationException` on the first request, blocking the application |
| `warning`   | Logs violations as warnings without interrupting execution                        |
| `disabled`  | No validation (default)                                                           |

Entity classes are discovered automatically from `mapping.entity.dir` — no manual list needed.

> Schema validation requires the GraphQL API to be reachable at boot time. Use `disabled` in environments where the API
> may not be available (CI, offline dev, etc.).
