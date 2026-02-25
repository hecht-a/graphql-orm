<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Expr;

final class ComparisonExpression implements FilterExpressionInterface
{
    public function __construct(
        private string $field,
        private string $operator,
        private mixed $value,
    ) {
    }

    public function toArray(): array
    {
        return [
            $this->field => [
                $this->operator => $this->value,
            ],
        ];
    }
}
