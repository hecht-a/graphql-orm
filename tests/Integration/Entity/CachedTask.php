<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Entity;

use GraphqlOrm\Mapping\Cache;
use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

#[Entity(name: 'tasks')]
#[Cache]
class CachedTask
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Field(mappedFrom: 'title')]
    public string $title;

    #[Field(mappedFrom: 'done')]
    public bool $done;

    #[Field(mappedFrom: 'due_date')]
    public ?\DateTimeImmutable $dueDate = null;

    #[Relation(mappedFrom: 'user')]
    public ?User $user = null;
}
