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
}
