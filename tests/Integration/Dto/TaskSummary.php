<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Dto;

use GraphqlOrm\Mapping\MapFrom;

final readonly class TaskSummary
{
    public function __construct(
        public int $id,
        public string $title,
        public ?\DateTimeImmutable $dueDate,
        #[MapFrom('user.name')]
        public ?string $userName = null,
    ) {
    }
}
