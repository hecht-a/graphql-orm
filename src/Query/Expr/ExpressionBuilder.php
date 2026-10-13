<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Expr;

use GraphqlOrm\Exception\InvalidArgumentException;

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

    public function gt(string $field, mixed $value): ComparisonExpression
    {
        return new ComparisonExpression($field, 'gt', $value);
    }

    public function gte(string $field, mixed $value): ComparisonExpression
    {
        return new ComparisonExpression($field, 'gte', $value);
    }

    public function lt(string $field, mixed $value): ComparisonExpression
    {
        return new ComparisonExpression($field, 'lt', $value);
    }

    public function lte(string $field, mixed $value): ComparisonExpression
    {
        return new ComparisonExpression($field, 'lte', $value);
    }

    public function isNotNull(string $field): ComparisonExpression
    {
        return new ComparisonExpression($field, 'isNull', false);
    }

    /**
     * One inequality per value, Data API Builder having no "not in" operator.
     *
     * @param mixed[] $values
     *
     * @throws InvalidArgumentException when no value is given
     */
    public function notIn(string $field, array $values): LogicalExpression
    {
        if ($values === []) {
            throw new InvalidArgumentException(\sprintf('notIn() needs at least one value: to exclude nothing from "%s", leave the condition out.', $field));
        }

        return $this->andX(...array_map(fn (mixed $value) => $this->neq($field, $value), array_values($values)));
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
