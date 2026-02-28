<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Query\Expr\FilterExpressionInterface;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\DABGraphqlWalker;
use GraphqlOrm\Query\Walker\GraphqlWalkerInterface;

final class DataApiBuilderDialect implements GraphqlQueryDialect
{
    public function extractCollection(array $data): array
    {
        /** @var array<string|int, mixed>[] $items */
        $items = $data['items'] ?? [];

        return $items;
    }

    public function createWalker(): GraphqlWalkerInterface
    {
        return new DABGraphqlWalker();
    }

    public function applyQueryOptions(array $arguments, QueryOptions $options): array
    {
        if ($options->limit !== null) {
            $arguments['first'] = $options->limit;
        }

        if ($options->orderBy !== null) {
            $arguments['orderBy'] = $options->orderBy;
        }

        if ($options->paginate) {
            if ($options->limit) {
                $arguments['first'] = $options->limit;
            }

            if ($options->cursor) {
                $arguments['after'] = $options->cursor;
            }
        }

        return $arguments;
    }

    public function applyFilter(?FilterExpressionInterface $filter): array
    {
        if (!$filter) {
            return [];
        }

        return ['filter' => $filter->toArray()];
    }
}
