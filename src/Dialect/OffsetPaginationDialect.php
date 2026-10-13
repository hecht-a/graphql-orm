<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

/**
 * A dialect paginating with an offset (Hasura `limit` / `offset`) instead of a server cursor. The cursor of a page is
 * the offset of the next one, and one extra row is requested to know whether a next page exists: the dialect sends
 * `limit()` + 1 as limit and the cursor as offset, the extra row being dropped before hydration.
 */
interface OffsetPaginationDialect
{
}
