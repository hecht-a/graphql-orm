<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Entity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[Entity(name: 'tasks', repositoryClass: FakeRepository::class)]
class Task
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Field(mappedFrom: 'title')]
    public string $title;

    #[Relation(mappedFrom: 'user')]
    public User $user;
}
