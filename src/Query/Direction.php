<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

enum Direction: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';

    /** @deprecated since 4.9, use Direction::Asc */
    public const ASC = self::Asc;

    /** @deprecated since 4.9, use Direction::Desc */
    public const DESC = self::Desc;
}
