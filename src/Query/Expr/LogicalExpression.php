<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Expr;

final class LogicalExpression implements FilterExpressionInterface
{
    /**
     * @param FilterExpressionInterface[] $expressions
     */
    public function __construct(
        private string $operator,
        private array $expressions,
    ) {
    }

    public function toArray(): array
    {
        return [
            $this->operator => array_map(fn ($expr) => $expr->toArray(), $this->expressions),
        ];
    }
}
