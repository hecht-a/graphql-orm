<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Expr;

final class ExpressionBuilder
{
    public function eq(string $field, mixed $value): ComparisonExpression
    {
        return new ComparisonExpression($field, 'eq', $value);
    }

    public function contains(string $field, string $value): ComparisonExpression
    {
        return new ComparisonExpression($field, 'contains', $value);
    }

    public function notContains(string $field, string $value): ComparisonExpression
    {
        return new ComparisonExpression($field, 'notContains', $value);
    }

    public function startsWith(string $field, string $value): ComparisonExpression
    {
        return new ComparisonExpression($field, 'startsWith', $value);
    }

    public function endsWith(string $field, string $value): ComparisonExpression
    {
        return new ComparisonExpression($field, 'endsWith', $value);
    }

    /**
     * @param mixed[] $values
     */
    public function in(string $field, array $values): ComparisonExpression
    {
        return new ComparisonExpression($field, 'in', $values);
    }

    public function isNull(string $field): ComparisonExpression
    {
        return new ComparisonExpression($field, 'isNull', true);
    }

    public function neq(string $field, mixed $value): ComparisonExpression
    {
        return new ComparisonExpression($field, 'neq', $value);
    }

    public function andX(FilterExpressionInterface ...$expr): LogicalExpression
    {
        return new LogicalExpression('and', $expr);
    }

    public function orX(FilterExpressionInterface ...$expr): LogicalExpression
    {
        return new LogicalExpression('or', $expr);
    }
}
