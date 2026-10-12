<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Exception\LogicException;
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

    /**
     * Standard GraphQL defines no ordering nor pagination arguments: they need a dialect matching the API.
     *
     * @throws LogicException when ordering or pagination is requested
     */
    public function applyQueryOptions(array $arguments, QueryOptions $options): array
    {
        if ($options->orderBy !== null || $options->paginate) {
            throw new LogicException(\sprintf('Ordering and pagination are not supported by "%s": configure a dialect matching your GraphQL API (see docs/dialects.md).', self::class));
        }

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
