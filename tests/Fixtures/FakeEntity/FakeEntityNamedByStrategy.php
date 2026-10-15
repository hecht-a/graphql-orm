<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Attribute\Identifier;
use GraphqlOrm\Attribute\Relation;

#[GraphqlEntity(name: 'tasks')]
class FakeEntityNamedByStrategy
{
    #[GraphqlField]
    #[Identifier]
    public int $id;

    #[GraphqlField]
    public string $title;

    #[GraphqlField]
    public ?\DateTimeImmutable $dueDate = null;

    #[GraphqlField(mappedFrom: 'deadline_at')]
    public ?\DateTimeImmutable $deadline = null;

    #[Relation]
    public ?FakeUser $assignedUser = null;
}
