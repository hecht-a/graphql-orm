<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Entity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

#[GraphqlEntity(name: 'tasks')]
class Task
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'title')]
    public string $title;

    #[GraphqlField(mappedFrom: 'done')]
    public bool $done;

    #[GraphqlField(mappedFrom: 'due_date')]
    public ?\DateTimeImmutable $dueDate = null;

    #[GraphqlField(mappedFrom: 'user')]
    public ?User $user = null;
}
