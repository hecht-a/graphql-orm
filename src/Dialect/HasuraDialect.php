<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Query\Expr\FilterExpressionInterface;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\GraphqlWalkerInterface;
use GraphqlOrm\Query\Walker\HasuraGraphqlWalker;

/**
 * Hasura (and APIs following its conventions): collections are plain lists, filtered with `where` and its `_`-prefixed
 * operators, ordered with `order_by` and limited with `limit`.
 *
 * Hasura paginates with an offset, not a cursor: `paginate()` is rejected.
 */
final class HasuraDialect implements GraphqlQueryDialect
{
    private const OPERATORS = [
        'eq' => '_eq',
        'neq' => '_neq',
        'in' => '_in',
        'isNull' => '_is_null',
        'contains' => '_like',
        'notContains' => '_nlike',
        'startsWith' => '_like',
        'endsWith' => '_like',
    ];

    private const LOGICAL_OPERATORS = [
        'and' => '_and',
        'or' => '_or',
    ];

    public function extractCollection(array $data): array
    {
        return $data;
    }

    public function createWalker(): GraphqlWalkerInterface
    {
        return new HasuraGraphqlWalker();
    }

    /**
     * @throws LogicException when cursor pagination is requested
     */
    public function applyQueryOptions(array $arguments, QueryOptions $options): array
    {
        if ($options->paginate) {
            throw new LogicException(\sprintf('Hasura paginates with an offset, not a cursor: paginate() is not supported by "%s".', self::class));
        }

        if ($options->orderBy !== null) {
            $arguments['order_by'] = array_map(HasuraOrderDirection::fromDirection(...), $options->orderBy);
        }

        if ($options->limit !== null) {
            $arguments['limit'] = $options->limit;
        }

        return $arguments;
    }

    public function applyFilter(?FilterExpressionInterface $filter): array
    {
        if ($filter === null) {
            return [];
        }

        return ['where' => $this->translate($filter->toArray())];
    }

    public function applyCriteria(array $criteria): array
    {
        if ($criteria === []) {
            return [];
        }

        return ['where' => array_map(
            fn (mixed $value) => $value === null ? ['_is_null' => true] : ['_eq' => $value],
            $criteria,
        )];
    }

    /**
     * Turns the generic filter (`{field: {eq: value}}`, `{and: [...]}`) into the Hasura boolean expression.
     *
     * @param array<mixed> $filter
     *
     * @return array<mixed>
     */
    private function translate(array $filter): array
    {
        $where = [];

        foreach ($filter as $key => $value) {
            if (isset(self::LOGICAL_OPERATORS[$key]) && \is_array($value)) {
                $where[self::LOGICAL_OPERATORS[$key]] = array_map(
                    fn (mixed $operand) => \is_array($operand) ? $this->translate($operand) : $operand,
                    $value,
                );

                continue;
            }

            $where[$key] = \is_array($value) ? $this->translateComparison($value) : $value;
        }

        return $where;
    }

    /**
     * @param array<mixed> $comparison
     *
     * @return array<mixed>
     */
    private function translateComparison(array $comparison): array
    {
        $translated = [];

        foreach ($comparison as $operator => $value) {
            $translated[self::OPERATORS[$operator] ?? $operator] = match ($operator) {
                'contains', 'notContains' => '%' . $this->escapeLike($value) . '%',
                'startsWith' => $this->escapeLike($value) . '%',
                'endsWith' => '%' . $this->escapeLike($value),
                default => $value,
            };
        }

        return $translated;
    }

    /**
     * Escapes the LIKE wildcards of a searched value, so that `A_1` does not match `AB1`.
     */
    private function escapeLike(mixed $value): string
    {
        return addcslashes(\is_scalar($value) ? (string) $value : '', '\\%_');
    }
}
