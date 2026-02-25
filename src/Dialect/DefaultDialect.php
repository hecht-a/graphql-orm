<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Query\Expr\FilterExpressionInterface;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\DefaultGraphqlWalker;
use GraphqlOrm\Query\Walker\GraphqlWalkerInterface;

final class DefaultDialect implements GraphqlQueryDialect
{
    public function extractCollection(array $data): array
    {
        return $data;
    }

    public function createWalker(): GraphqlWalkerInterface
    {
        return new DefaultGraphqlWalker();
    }

    public function applyQueryOptions(array $arguments, QueryOptions $options): array
    {
        if ($options->limit !== null) {
            $arguments['first'] = $options->limit;
        }

        return $arguments;
    }

    public function applyFilter(?FilterExpressionInterface $filter): array
    {
        if (!$filter) {
            return [];
        }

        return $filter->toArray();
    }
}
