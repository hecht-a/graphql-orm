<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Entity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Attribute\Identifier;
use GraphqlOrm\Attribute\Relation;
use GraphqlOrm\Tests\Fixtures\Repository\FakeRepository;

#[GraphqlEntity(name: 'tasks', repositoryClass: FakeRepository::class)]
class Task
{
    #[GraphqlField(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[GraphqlField(mappedFrom: 'title')]
    public string $title;

    #[Relation(mappedFrom: 'user')]
    public User $user;
}
