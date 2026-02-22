<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Entity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Tests\Fixtures\Repository\FakeRepository;

#[GraphqlEntity(name: 'tasks', repositoryClass: FakeRepository::class)]
class Task
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'title')]
    public string $title;

    #[GraphqlField(mappedFrom: 'user')]
    public User $user;
}
