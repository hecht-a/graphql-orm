<?php

declare(strict_types=1);

namespace GraphqlOrm\Pagination;

use GraphqlOrm\Dialect\CountingDialect;
use GraphqlOrm\Dialect\OffsetPaginationDialect;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Query\GraphqlQueryBuilder;
use GraphqlOrm\Query\Pagination\PaginatedResult;

/**
 * The pages of a query builder by offset, as the paginators count and slice them: the number of entities, then the
 * rows of a page. The query builder given is left as it is.
 *
 * @internal
 *
 * @template T of object
 */
final readonly class OffsetPages
{
    /**
     * @param GraphqlQueryBuilder<T> $queryBuilder
     *
     * @throws LogicException when the dialect paginates with cursors, or cannot count
     */
    public function __construct(
        private GraphqlQueryBuilder $queryBuilder,
    ) {
        $dialect = $queryBuilder->getDialect();

        if (!$dialect instanceof OffsetPaginationDialect || !$dialect instanceof CountingDialect) {
            throw new LogicException(\sprintf('"%s" %s: a page by its number needs a dialect paginating with an offset (%s) and counting the entities (%s). Use paginate() and next() instead.', $dialect::class, $dialect instanceof OffsetPaginationDialect ? 'cannot count the entities' : 'paginates with cursors', OffsetPaginationDialect::class, CountingDialect::class));
        }
    }

    public function count(): int
    {
        return $this->queryBuilder->count();
    }

    /**
     * @return list<T>
     */
    public function slice(int $offset, int $length): array
    {
        $result = (clone $this->queryBuilder)
            ->paginate($offset > 0 ? (string) $offset : null)
            ->limit($length)
            ->getQuery()
            ->getResult();

        return array_values($result instanceof PaginatedResult ? $result->items : $result);
    }
}
