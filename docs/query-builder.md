# Query Builder

The Query Builder constructs GraphQL queries programmatically.

## Field selection

```php
$qb = $repository->createQueryBuilder();

// Select specific fields (dot notation for nested fields)
$qb->select('id', 'title', 'user.name', 'user.email');

// Add fields incrementally
$qb->addSelect('dueDate');
```

## Filtering

Use `ExpressionBuilder` to build type-safe filter expressions:

```php
$qb = $repository->createQueryBuilder();
$expr = $qb->expr();

// Simple equality
$qb->where($expr->eq('status', 'active'));

// Available operators
$expr->eq('field', $value)           // field == value
$expr->neq('field', $value)          // field != value
$expr->contains('field', $value)     // field contains value
$expr->notContains('field', $value)  // field does not contain value
$expr->startsWith('field', $value)   // field starts with value
$expr->endsWith('field', $value)     // field ends with value
$expr->in('field', [$a, $b])         // field in [a, b]
$expr->isNull('field')               // field is null

// Logical combinators
$expr->andX($expr->eq('status', 'active'), $expr->isNull('dueDate'))
$expr->orX($expr->eq('priority', 'high'), $expr->eq('priority', 'critical'))
```

## Ordering and limit

```php
use GraphqlOrm\Query\Direction;

$qb
    ->orderBy('createdAt', Direction::Desc)
    ->limit(20);
```

## Raw GraphQL query

You can bypass the builder entirely and provide a raw query string:

```php
$result = $repository->createQueryBuilder()
    ->setGraphQL('
        query {
            tasks {
                id
                title
            }
        }
    ')
    ->getQuery()
    ->getResult();
```

## Inspecting the generated query

```php
$qb = $repository->createQueryBuilder();
$graphql = $qb
    ->select('id', 'title')
    ->where($qb->expr()->eq('id', 1))
    ->getQuery()
    ->getGraphQL();

// outputs:
// query {
//   tasks(id: 1) {
//     id
//     title
//   }
// }
```
