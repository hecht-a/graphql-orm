<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Entity;

final readonly class Title
{
    public function __construct(
        public string $value,
    ) {
    }
}
