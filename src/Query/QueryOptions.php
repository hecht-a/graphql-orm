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
}
