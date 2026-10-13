<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\EnumLiteral;
use GraphqlOrm\Query\Expr\FilterExpressionInterface;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\GraphqlWalkerInterface;
use GraphqlOrm\Query\Walker\RelayGraphqlWalker;

/**
 * PostGraphile: Relay connections (`allTasks { edges { node } pageInfo }`), criteria sent as a `condition`, ordering
 * with the `orderBy` enum values of the fields (`orderBy: [DUE_DATE_DESC]`).
 *
 * The query builder filters need postgraphile-plugin-connection-filter, which core PostGraphile does not provide: they
 * are rejected. Without a list filter, lazy relations are loaded one query each.
 */
final class PostGraphileDialect implements GraphqlQueryDialect, CursorPaginationDialect
{
    private RelayDialect $relay;

    public function __construct()
    {
        $this->relay = new RelayDialect();
    }

    public function extractCollection(array $data): array
    {
        return $this->relay->extractCollection($data);
    }

    public function createWalker(): GraphqlWalkerInterface
    {
        return new RelayGraphqlWalker();
    }

    public function applyQueryOptions(array $arguments, QueryOptions $options): array
    {
        if ($options->orderBy !== null) {
            $arguments['orderBy'] = array_map(
                fn (string $field, Direction $direction) => new EnumLiteral($this->constantCase($field) . '_' . $direction->value),
                array_keys($options->orderBy),
                $options->orderBy,
            );
        }

        if ($options->limit !== null) {
            $arguments['first'] = $options->limit;
        }

        if ($options->paginate && $options->cursor !== null) {
            $arguments['after'] = $options->cursor;
        }

        return $arguments;
    }

    /**
     * @throws LogicException when a filter is given: core PostGraphile has none
     */
    public function applyFilter(?FilterExpressionInterface $filter): array
    {
        if ($filter === null) {
            return [];
        }

        throw new LogicException(\sprintf('The query builder filters need postgraphile-plugin-connection-filter, which "%s" does not support: use the find methods, sent as a condition.', self::class));
    }

    public function applyCriteria(array $criteria): array
    {
        return $criteria === [] ? [] : ['condition' => $criteria];
    }

    public function hasNextPage(array $root): bool
    {
        return $this->relay->hasNextPage($root);
    }

    public function endCursor(array $root): ?string
    {
        return $this->relay->endCursor($root);
    }

    /**
     * `dueDate` => `DUE_DATE`, as PostGraphile names the values of its orderBy enums.
     */
    private function constantCase(string $field): string
    {
        return strtoupper((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $field));
    }
}
