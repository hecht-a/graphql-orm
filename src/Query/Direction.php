<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

enum Direction: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';
}
