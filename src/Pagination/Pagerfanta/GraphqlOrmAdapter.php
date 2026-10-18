<?php

declare(strict_types=1);

namespace GraphqlOrm\Pagination\Pagerfanta;

use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Pagination\OffsetPages;
use GraphqlOrm\Query\GraphqlQueryBuilder;
use Pagerfanta\Adapter\AdapterInterface;

/**
 * Paginates a query builder with Pagerfanta: a count of the entities its filter matches, then the rows of the page by
 * offset. Needs a dialect paginating with an offset and counting the entities, as HasuraDialect.
 *
 * @template T of object
 *
 * @implements AdapterInterface<T>
 */
final readonly class GraphqlOrmAdapter implements AdapterInterface
{
    /** @var OffsetPages<T> */
    private OffsetPages $pages;

    /**
     * @param GraphqlQueryBuilder<T> $queryBuilder left as it is: each page is a copy of it
     *
     * @throws LogicException when the dialect paginates with cursors, or cannot count
     */
    public function __construct(GraphqlQueryBuilder $queryBuilder)
    {
        $this->pages = new OffsetPages($queryBuilder);
    }

    public function getNbResults(): int
    {
        return max(0, $this->pages->count());
    }

    public function getSlice(int $offset, int $length): iterable
    {
        return $this->pages->slice($offset, $length);
    }
}
