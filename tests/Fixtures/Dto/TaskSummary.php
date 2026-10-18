<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Dto;

use GraphqlOrm\Mapping\MapFrom;

final readonly class TaskSummary
{
    public function __construct(
        public int $id,
        public string $title,
        public ?\DateTimeImmutable $dueDate,
        public ?Priority $priority,
        #[MapFrom('assignee.name')]
        public ?string $assigneeName = null,
    ) {
    }
}
