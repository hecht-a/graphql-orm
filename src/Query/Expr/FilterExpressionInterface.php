<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Expr;

interface FilterExpressionInterface
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(): array;
}
