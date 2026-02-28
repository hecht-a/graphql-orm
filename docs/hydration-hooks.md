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
use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

#[GraphqlEntity(name: 'products', repositoryClass: ProductRepository::class)]
class Product
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'name')]
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

- The method must be **public** and accept a **single `array` parameter**
- Multiple `#[BeforeHydrate]` methods are supported — all are called
- Always runs when the entity is first created — no partial hydration check
- At the time the hook runs, no fields are assigned yet

---

## `#[AfterHydrate]`

Called after all fields have been assigned. Use it to compute virtual fields or run any post-hydration logic.

```php
use GraphqlOrm\Attribute\AfterHydrate;
use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

#[GraphqlEntity(name: 'tasks', repositoryClass: TaskRepository::class)]
class Task
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'title')]
    public string $title;

    #[GraphqlField(mappedFrom: 'dueDate')]
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

- The method must be **public** and take **no arguments**
- Multiple `#[AfterHydrate]` methods are supported — all are called
- **Skipped** if any mapped field (`#[GraphqlField]`) is not initialized — this happens on partial hydration (e.g. a
  nested relation with only a subset of fields selected)
- Virtual fields (no `#[GraphqlField]`) are ignored in this check — they are meant to be set by the hook

### Partial hydration

When a relation is loaded with only a subset of fields, `#[AfterHydrate]` is skipped to avoid accessing uninitialized
properties:

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
#[GraphqlEntity(name: 'products', repositoryClass: ProductRepository::class)]
class Product
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'price')]
    public float $price;

    #[GraphqlField(mappedFrom: 'taxRate')]
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

