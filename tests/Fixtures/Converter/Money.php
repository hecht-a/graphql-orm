<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Converter;

final readonly class Money
{
    public function __construct(
        public int $cents,
    ) {
    }
}
