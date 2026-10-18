<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Dto;

enum Priority: string
{
    case Low = 'low';
    case High = 'high';
}
