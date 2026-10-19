<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Query\QueryOptions;

/**
 * The arguments of a page fetched with Relay cursors: `first`, its size, and `after`, the cursor of the page before it.
 * The dialects apply them when the query is built, the walkers again for each page fetched.
 *
 * @internal
 */
final class CursorPageArguments
{
    /**
     * @param array<string, mixed> $arguments the root arguments; the cursor they hold is replaced by the one of the page
     *
     * @return array<string, mixed>
     */
    public static function apply(array $arguments, QueryOptions $options): array
    {
        if ($options->limit !== null) {
            $arguments['first'] = $options->limit;
        }

        if (!$options->paginate) {
            return $arguments;
        }

        unset($arguments['after']);

        if ($options->cursor !== null) {
            $arguments['after'] = $options->cursor;
        }

        return $arguments;
    }
}
