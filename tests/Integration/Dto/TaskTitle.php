<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Dto;

final readonly class TaskTitle
{
    public function __construct(
        public string $title,
        public bool $done,
    ) {
    }
}
