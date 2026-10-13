<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Query\Expr\FilterExpressionInterface;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\GraphqlWalkerInterface;
use GraphqlOrm\Query\Walker\RelayGraphqlWalker;

/**
 * Relay cursor connections (GitHub, Shopify, most Apollo and Hot Chocolate servers): collections are
 * `edges { node { ... } }`, paginated with `first` / `after` and `pageInfo { hasNextPage endCursor }`.
 *
 * Relay standardizes neither filtering nor ordering: criteria and filters are sent as root arguments, ordering is
 * rejected.
 */
final class RelayDialect implements GraphqlQueryDialect, CursorPaginationDialect
{
    public function extractCollection(array $data): array
    {
        $edges = $data['edges'] ?? null;

        if (!\is_array($edges)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $edge) => \is_array($edge) ? ($edge['node'] ?? null) : null,
            $edges,
        ));
    }

    public function createWalker(): GraphqlWalkerInterface
    {
        return new RelayGraphqlWalker();
    }

    /**
     * @throws LogicException when ordering is requested
     */
    public function applyQueryOptions(array $arguments, QueryOptions $options): array
    {
        if ($options->orderBy !== null) {
            throw new LogicException(\sprintf('Ordering is not standardized by Relay and not supported by "%s": configure a dialect matching your GraphQL API (see docs/dialects.md).', self::class));
        }

        if ($options->limit !== null) {
            $arguments['first'] = $options->limit;
        }

        if ($options->paginate && $options->cursor !== null) {
            $arguments['after'] = $options->cursor;
        }

        return $arguments;
    }

    public function applyFilter(?FilterExpressionInterface $filter): array
    {
        return $filter?->toArray() ?? [];
    }

    public function applyCriteria(array $criteria): array
    {
        return $criteria;
    }

    public function hasNextPage(array $root): bool
    {
        return ($this->pageInfo($root)['hasNextPage'] ?? false) === true;
    }

    public function endCursor(array $root): ?string
    {
        $cursor = $this->pageInfo($root)['endCursor'] ?? null;

        return \is_string($cursor) ? $cursor : null;
    }

    /**
     * @param array<mixed> $root
     *
     * @return array<mixed>
     */
    private function pageInfo(array $root): array
    {
        $pageInfo = $root['pageInfo'] ?? null;

        return \is_array($pageInfo) ? $pageInfo : [];
    }
}
