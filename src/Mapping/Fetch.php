<?php

declare(strict_types=1);

namespace GraphqlOrm\Mapping;

/**
 * When a relation is loaded, as Doctrine fetch modes.
 */
enum Fetch
{
    /** Selected with its entity, up to max_depth. */
    case Eager;

    /** Selected by its identifier only, and loaded on first access with the other relations of the response. */
    case Lazy;
}
