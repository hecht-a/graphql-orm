<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Query\Expr\FilterExpressionInterface;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\GraphqlWalkerInterface;

interface GraphqlQueryDialect
{
    /**
     * Extracts the rows of a collection field, e.g. the `items` of a Data API Builder connection.
     *
     * @param array<mixed> $data the decoded value of the collection field
     *
     * @return array<mixed> the rows (a single row when the field is an object)
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

    /**
     * Turns the equality criteria of the repository find methods into root arguments.
     *
     * @param array<string, mixed> $criteria field name => expected value
     *
     * @return array<string, mixed>
     */
    public function applyCriteria(array $criteria): array;
}
