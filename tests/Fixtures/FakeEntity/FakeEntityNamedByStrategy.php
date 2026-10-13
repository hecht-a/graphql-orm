<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

#[GraphqlEntity(name: 'tasks')]
class FakeEntityNamedByStrategy
{
    #[GraphqlField(identifier: true)]
    public int $id;

    #[GraphqlField]
    public string $title;

    #[GraphqlField]
    public ?\DateTimeImmutable $dueDate = null;

    #[GraphqlField(mappedFrom: 'deadline_at')]
    public ?\DateTimeImmutable $deadline = null;

    #[GraphqlField]
    public ?FakeUser $assignedUser = null;
}
