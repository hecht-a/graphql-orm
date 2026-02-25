<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Query\Expr\FilterExpressionInterface;

final class QueryOptions
{
    public ?int $limit = null;

    public ?FilterExpressionInterface $filter = null;

    /** @var array<string, Direction>|null */
    public ?array $orderBy = null;
}
