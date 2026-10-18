<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Dto;

final readonly class TaskLine
{
    public function __construct(
        public string $title,
        public bool $done,
    ) {
    }
}
