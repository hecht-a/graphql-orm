<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

final class QueryOptions
{
    public ?int $limit = null;

    /** @var array<string, Direction>|null */
    public ?array $orderBy = null;

    public bool $paginate = false;

    public ?string $cursor = null;

    /** @var string[] */
    public array $cursorStack = [];

    /**
     * Kept out of the shared identity map, so that going through every page holds a single page in memory.
     *
     * @internal set by GraphqlQuery::toIterable()
     */
    public bool $detached = false;

    /**
     * The property keying the result, as Doctrine indexBy.
     *
     * @internal set by GraphqlQueryBuilder::indexBy() and GraphqlEntityRepository::findBy()
     */
    public ?string $indexBy = null;

    /**
     * The relations named by with(), as written: told to the profiler.
     *
     * @internal set by GraphqlQueryBuilder::with() and GraphqlEntityRepository::with()
     *
     * @var list<string>
     */
    public array $with = [];

    /** Whether the response is kept in the result cache and read from it. */
    public bool $resultCache = false;

    /** Seconds the response is kept, null for the default lifetime of the pool. */
    public ?int $resultCacheLifetime = null;

    /** The key the response is kept under, to evict it; derived from the query and its variables when null. */
    public ?string $resultCacheKey = null;
}
