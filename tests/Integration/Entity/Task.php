<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Entity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Attribute\Identifier;
use GraphqlOrm\Attribute\Relation;

#[GraphqlEntity(name: 'tasks')]
class Task
{
    #[GraphqlField(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[GraphqlField(mappedFrom: 'title')]
    public string $title;

    #[GraphqlField(mappedFrom: 'done')]
    public bool $done;

    #[GraphqlField(mappedFrom: 'due_date')]
    public ?\DateTimeImmutable $dueDate = null;

    #[Relation(mappedFrom: 'user')]
    public ?User $user = null;
}
