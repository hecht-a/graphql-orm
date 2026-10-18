<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Dto;

final readonly class UserWithTasks
{
    /**
     * @param list<TaskLine> $tasks
     */
    public function __construct(
        public string $name,
        public array $tasks,
    ) {
    }
}
