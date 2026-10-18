<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Embedded;

final readonly class NotEmbeddable
{
    public function __construct(
        public int $amount,
    ) {
    }
}
