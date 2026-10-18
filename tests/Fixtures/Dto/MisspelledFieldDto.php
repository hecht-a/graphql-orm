<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Dto;

final readonly class MisspelledFieldDto
{
    public function __construct(
        public string $titel,
    ) {
    }
}
