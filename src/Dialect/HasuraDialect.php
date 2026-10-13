<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;
use GraphqlOrm\Query\Expr\FilterExpressionInterface;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\GraphqlWalkerInterface;
use GraphqlOrm\Query\Walker\HasuraGraphqlWalker;

/**
 * Hasura (and APIs following its conventions): collections are plain lists, filtered with `where` and its `_`-prefixed
 * operators, ordered with `order_by` and limited with `limit`.
 *
 * Hasura paginates with an offset: `paginate()` needs a `limit()`, the cursor of a page being the offset of the next one.
 */
final class HasuraDialect implements GraphqlQueryDialect, BatchLoadingDialect, OffsetPaginationDialect, CountingDialect
{
    private const OPERATORS = [
        'eq' => '_eq',
        'neq' => '_neq',
        'gt' => '_gt',
        'gte' => '_gte',
        'lt' => '_lt',
        'lte' => '_lte',
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
     * @throws LogicException           when paginating without page size
     * @throws InvalidArgumentException when the cursor is not an offset
     */
    public function applyQueryOptions(array $arguments, QueryOptions $options): array
    {
        if ($options->orderBy !== null) {
            $arguments['order_by'] = array_map(HasuraOrderDirection::fromDirection(...), $options->orderBy);
        }

        if ($options->limit !== null) {
            $arguments['limit'] = $options->limit;
        }

        return self::withPagination($arguments, $options);
    }

    /**
     * The `limit` (one extra row) and `offset` of the page to fetch, also applied by the walker as each page reuses the
     * arguments the query was built with.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     *
     * @throws LogicException           when paginating without page size
     * @throws InvalidArgumentException when the cursor is not an offset
     */
    public static function withPagination(array $arguments, QueryOptions $options): array
    {
        if (!$options->paginate) {
            return $arguments;
        }

        if ($options->limit === null) {
            throw new LogicException('Hasura paginates with an offset: paginate() needs a page size, set with limit().');
        }

        $arguments['limit'] = $options->limit + 1;
        unset($arguments['offset']);

        if ($options->cursor !== null) {
            if (!ctype_digit($options->cursor)) {
                throw new InvalidArgumentException(\sprintf('The cursor "%s" is not an offset: Hasura paginates with offsets.', $options->cursor));
            }

            $arguments['offset'] = (int) $options->cursor;
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

    /**
     * `tasks_aggregate(where) { aggregate { count } }`.
     */
    public function countField(string $root, array $arguments, ?string $identifierField): FieldNode
    {
        $aggregate = new SelectionSetNode();
        $aggregate->add(new FieldNode('count'));
        $selection = new SelectionSetNode();
        $selection->add(new FieldNode('aggregate', [], $aggregate));

        return new FieldNode($root . '_aggregate', $arguments, $selection);
    }

    public function readCount(array $data, string $countRoot): int
    {
        $aggregate = \is_array($data[$countRoot] ?? null) ? ($data[$countRoot]['aggregate'] ?? null) : null;
        $count = \is_array($aggregate) ? ($aggregate['count'] ?? null) : null;

        return \is_int($count) ? $count : throw InvalidGraphqlResponseException::expectedArray($data[$countRoot] ?? null);
    }
}
