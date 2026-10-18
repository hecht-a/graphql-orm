<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Dto;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

#[Entity(name: 'tasks')]
class Task
{
    #[Field]
    #[Identifier]
    public int $id;

    #[Field]
    public string $title;

    #[Field]
    public bool $done = false;

    #[Field(mappedFrom: 'due_date')]
    public ?\DateTimeImmutable $dueDate = null;

    #[Field]
    public ?Priority $priority = null;

    #[Relation]
    public ?User $assignee = null;
}
