<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Dto;

final readonly class UserWithTaskTitles
{
    /**
     * @param list<TaskTitle> $tasks
     */
    public function __construct(
        public string $name,
        public array $tasks,
    ) {
    }
}
