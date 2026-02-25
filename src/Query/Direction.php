<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

enum Direction: string
{
    case ASC = 'ASC';
    case DESC = 'DESC';
}
