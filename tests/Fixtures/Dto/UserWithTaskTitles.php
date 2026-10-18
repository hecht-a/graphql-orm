<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Dto;

final readonly class UserWithTaskTitles
{
    public function __construct(
        public string $name,
        /** @var list<TaskLine> */
        public array $tasks = [],
    ) {
    }
}
