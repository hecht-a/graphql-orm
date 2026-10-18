<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Converter;

final readonly class Siret
{
    public function __construct(
        public string $value,
    ) {
    }
}
