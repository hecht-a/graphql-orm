<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Query\Direction;

/**
 * Values of the Hasura `order_by` enum, printed as unquoted enum literals.
 */
enum HasuraOrderDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';

    public static function fromDirection(Direction $direction): self
    {
        return match ($direction) {
            Direction::Asc => self::Asc,
            Direction::Desc => self::Desc,
        };
    }
}
