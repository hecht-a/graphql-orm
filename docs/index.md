---
layout: home

hero:
  name: GraphQL ORM
  text: A GraphQL ORM for PHP and Symfony
  tagline: Map PHP objects to a GraphQL API with attributes, query it with repositories and a query builder, the way Doctrine does with SQL.
  actions:
    - theme: brand
      text: Get started
      link: /configuration
    - theme: alt
      text: View on GitHub
      link: https://github.com/hecht-a/graphql-orm

features:
  - title: Repositories and query builder
    details: find(), findBy(), count() and a query builder with filters, ordering, pagination and array results.
  - title: Several GraphQL dialects
    details: Data API Builder, Hasura, PostGraphile, Relay connections and standard GraphQL, each tested against a real server.
  - title: Hydration
    details: Relations, collections, dates and enums, an identity map, hooks, and lazy relations loaded in batches.
  - title: Safety
    details: Schema validation in CI, PHPStan rules for field names, typed GraphQL variables, values masked in logs.
  - title: Symfony integration
    details: Profiler panel spotting N+1 queries, events for metrics and tracing, entity generation from the schema.
---

## Installation

```bash
composer require hecht-a/graphql-orm
```

Requirements: PHP 8.4+ and Symfony 7.4 or 8.x. The bundle relies on the HTTP client service provided by
FrameworkBundle (`framework.http_client`, enabled by default when `symfony/http-client` is installed).

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
php bin/console graphqlorm:make:entity Task --from-schema
```

```php
$tasks = $taskRepository->findBy(['status' => 'active'], ['createdAt' => Direction::Desc], 10);

$qb = $taskRepository->createQueryBuilder();
$tasks = $qb
    ->select('id', 'title', 'user.name')
    ->where($qb->expr()->eq('status', 'active'))
    ->limit(10)
    ->getQuery()
    ->getResult();
```

Next: [configure the bundle](configuration.md), then [map your entities](entity-mapping.md).
