# Hydration hooks

GraphQL ORM provides two lifecycle hooks that run around the hydration of an entity.

| Hook               | Runs                    | Arguments                        | Use case                                |
|--------------------|-------------------------|----------------------------------|-----------------------------------------|
| `#[BeforeHydrate]` | Before field assignment | `array $data` (raw GraphQL data) | Capture unmapped fields, raw metadata   |
| `#[AfterHydrate]`  | After field assignment  | none                             | Compute virtual fields, post-processing |

---

## `#[BeforeHydrate]`

Called just before fields are assigned to the entity. Receives the **raw GraphQL data array**, making it possible to
capture fields that are not mapped (e.g. `__typename`, pagination metadata).

```php
use GraphqlOrm\Attribute\BeforeHydrate;
use GraphqlOrm\Mapping as GraphqlOrm;

#[GraphqlOrm\Entity(name: 'products', repositoryClass: ProductRepository::class)]
class Product
{
    #[GraphqlOrm\Field(mappedFrom: 'id')]
    #[GraphqlOrm\Identifier]
    public int $id;

    #[GraphqlOrm\Field(mappedFrom: 'name')]
    public string $name;

    // Populated from raw data — not a mapped field
    public string $graphqlType = '';

    /**
     * @param array<string, mixed> $data
     */
    #[BeforeHydrate]
    public function onBeforeHydrate(array $data): void
    {
        $this->graphqlType = $data['__typename'] ?? 'unknown';
    }
}
```

### Rules

- The method must be **public** and accept a **single `array` parameter**: a method that is not public is ignored
- Multiple `#[BeforeHydrate]` methods are supported — all are called
- `$data` is the row of the entity, keyed by the GraphQL field names
- Runs once, when the entity is created from a row — no partial hydration check. An entity already known (the
  identity map) that gets the fields of another row does not run it again; a [lazy](entity-mapping.md#lazy-relations)
  ghost runs it when it is loaded
- At the time the hook runs, no fields are assigned yet

---

## `#[AfterHydrate]`

Called after all fields have been assigned. Use it to compute virtual fields or run any post-hydration logic.

```php
use GraphqlOrm\Attribute\AfterHydrate;
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
    public ?\DateTimeImmutable $dueDate = null;

    // Virtual field — not in the GraphQL schema
    public bool $isOverdue = false;

    #[AfterHydrate]
    public function compute(): void
    {
        $this->isOverdue = $this->dueDate !== null
            && $this->dueDate < new \DateTimeImmutable();
    }
}
```

### Rules

- The method must be **public** and take **no arguments**: a method that is not public is ignored
- Multiple `#[AfterHydrate]` methods are supported — all are called
- **Skipped** if any mapped property (`#[GraphqlOrm\Field]`, `#[GraphqlOrm\Relation]`, `#[GraphqlOrm\Embedded]`) is not
  initialized — this happens on partial hydration (e.g. a nested relation with only a subset of fields selected)
- Run later, once the missing fields are assigned: when the entity is met again in a row holding them, or when a
  lazy ghost is loaded
- Virtual fields (no mapping attribute) are ignored in this check — they are meant to be set by the hook — and so
  are the mapped properties having a default value or no type

### Partial hydration

When an entity is loaded with only a subset of its fields, a relation or a
[selection of the query builder](query-builder.md#field-selection), `#[AfterHydrate]` is skipped to avoid accessing
uninitialized properties:

```graphql
query {
  users {
    items {
      id
      tasks {
        items {
          id   # only id selected — AfterHydrate on Task is skipped
        }
      }
    }
  }
}
```

---

## Using both hooks together

The two hooks can coexist on the same entity. Execution order is always: **BeforeHydrate → field assignment →
AfterHydrate**.

```php
use GraphqlOrm\Attribute\AfterHydrate;
use GraphqlOrm\Attribute\BeforeHydrate;
use GraphqlOrm\Mapping as GraphqlOrm;

#[GraphqlOrm\Entity(name: 'products', repositoryClass: ProductRepository::class)]
class Product
{
    #[GraphqlOrm\Field(mappedFrom: 'id')]
    #[GraphqlOrm\Identifier]
    public int $id;

    #[GraphqlOrm\Field(mappedFrom: 'price')]
    public float $price;

    #[GraphqlOrm\Field(mappedFrom: 'taxRate')]
    public float $taxRate;

    public string $graphqlType = '';
    public float $priceWithTax = 0.0;

    /**
     * @param array<string, mixed> $data
     */
    #[BeforeHydrate]
    public function captureMetadata(array $data): void
    {
        $this->graphqlType = $data['__typename'] ?? 'unknown';
    }

    #[AfterHydrate]
    public function computePrices(): void
    {
        $this->priceWithTax = $this->price * (1 + $this->taxRate / 100);
    }
}
```

