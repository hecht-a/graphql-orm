<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Query\Expr\FilterExpressionInterface;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\GraphqlWalkerInterface;

interface GraphqlQueryDialect
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array<string|int, mixed>
     */
    public function extractCollection(array $data): array;

    public function createWalker(): GraphqlWalkerInterface;

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function applyQueryOptions(array $arguments, QueryOptions $options): array;

    /**
     * @return array<string, mixed>
     */
    public function applyFilter(?FilterExpressionInterface $filter): array;
}
