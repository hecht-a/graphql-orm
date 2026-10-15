# GraphQL ORM

**GraphQL ORM** is a lightweight **GraphQL ORM for PHP/Symfony**, inspired by concepts from **Doctrine ORM**.

It allows you to map PHP objects to a GraphQL API using attributes, and provides repositories, a query builder,
automatic hydration, relation handling, schema validation, and deep Symfony integration.

---

## Installation

```bash
composer require hecht-a/graphql-orm
```

Requirements: PHP 8.4+ and Symfony 7.4 or 8.x.

The bundle relies on the HTTP client service provided by FrameworkBundle (`framework.http_client`, enabled by default
when `symfony/http-client` is installed).

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

$qb = $taskRepository->createQueryBuilder();
$tasks = $qb
    ->select('id', 'title', 'user.name')
    ->where($qb->expr()->eq('status', 'active'))
    ->limit(10)
    ->getQuery()
    ->getResult();
```

---

## Documentation

The documentation is published at **https://hecht-a.github.io/graphql-orm/**, and its pages live in `docs/`:

- [Configuration](docs/configuration.md)
- [Entity mapping](docs/entity-mapping.md)
- [Repositories](docs/repositories.md)
- [Query Builder](docs/query-builder.md)
- [Pagination](docs/pagination.md)
- [Dialects](docs/dialects.md)
- [Schema validation](docs/schema-validation.md)
- [Logging](docs/logging.md)
- [Profiler](docs/profiler.md)
- [Events](docs/events.md) — metrics, tracing
- [Hydration hooks](docs/hydration-hooks.md) — `#[BeforeHydrate]`, `#[AfterHydrate]`
- [Forms](docs/forms.md) — `GraphqlEntityType`, a choice among entities
- [Serializer](docs/serializer.md) — entities written without loading their lazy relations
- [Console commands](docs/commands.md)
- [PHPStan rules](docs/phpstan.md) — field names checked at analysis time
- [Testing](docs/testing.md) — `MockGraphqlServer`, a GraphQL server for the tests of your application
- [Upgrading from 4.x](docs/upgrading.md)

## License

MIT, see [LICENSE](LICENSE).
