# GraphQL ORM

**GraphQL ORM** is a lightweight **GraphQL ORM for PHP/Symfony**, inspired by concepts from **Doctrine ORM**.

It allows you to map PHP objects to a GraphQL API using attributes, and provides repositories, a query builder,
automatic hydration, relation handling, schema validation, and deep Symfony integration.

---

## Installation

```bash
composer require hecht-a/graphql-orm
```

## Quick start

```yaml
# config/packages/graphql_orm.yaml
graphql_orm:
  endpoint: 'http://localhost:4000/graphql'
  mapping:
    entity:
      dir: '%kernel.project_dir%/src/GraphQL/Entity'
      namespace: App\GraphQL\Entity
    repository:
      dir: '%kernel.project_dir%/src/GraphQL/Repository'
      namespace: App\GraphQL\Repository
```

```bash
php bin/console graphqlorm:make:entity Task
```

```php
$tasks = $taskRepository->findAll();

$tasks = $taskRepository->createQueryBuilder()
    ->select('id', 'title', 'user.name')
    ->where($qb->expr()->eq('status', 'active'))
    ->limit(10)
    ->getQuery()
    ->getResult();
```

---

## Documentation

- [Configuration](docs/configuration.md)
- [Entity mapping](docs/entity-mapping.md)
- [Repositories](docs/repositories.md)
- [Query Builder](docs/query-builder.md)
- [Pagination](docs/pagination.md)
- [Dialects](docs/dialects.md)
- [Schema validation](docs/schema-validation.md)
- [Logging](docs/logging.md)
- [Profiler](docs/profiler.md)
- [Hydration hooks](docs/hydration-hooks.md) — `#[BeforeHydrate]`, `#[AfterHydrate]`
- [Console commands](docs/commands.md)
