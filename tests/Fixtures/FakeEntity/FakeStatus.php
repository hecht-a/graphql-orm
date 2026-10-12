<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

enum FakeStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';
}
