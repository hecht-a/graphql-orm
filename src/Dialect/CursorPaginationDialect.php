<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

/**
 * A dialect telling where the cursor pagination information of a connection lies. Without it, `hasNextPage` and
 * `endCursor` are read on the root field itself (Data API Builder layout).
 */
interface CursorPaginationDialect
{
    /**
     * @param array<mixed> $root the decoded value of the root field
     */
    public function hasNextPage(array $root): bool;

    /**
     * @param array<mixed> $root the decoded value of the root field
     */
    public function endCursor(array $root): ?string;
}
