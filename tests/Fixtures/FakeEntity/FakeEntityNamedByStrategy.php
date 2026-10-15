<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

#[Entity(name: 'tasks')]
class FakeEntityNamedByStrategy
{
    #[Field]
    #[Identifier]
    public int $id;

    #[Field]
    public string $title;

    #[Field]
    public ?\DateTimeImmutable $dueDate = null;

    #[Field(mappedFrom: 'deadline_at')]
    public ?\DateTimeImmutable $deadline = null;

    #[Relation]
    public ?FakeUser $assignedUser = null;
}
