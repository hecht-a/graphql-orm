<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

/**
 * A dialect whose filter supports `in()` on the identifier: the lazy ghosts of a class met in a response are then
 * loaded with one query, instead of one query each.
 */
interface BatchLoadingDialect
{
}
