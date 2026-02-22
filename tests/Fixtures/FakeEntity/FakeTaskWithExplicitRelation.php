<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[GraphqlEntity(name: 'tasks', repositoryClass: FakeRepository::class)]
class FakeTaskWithExplicitRelation
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'user', targetEntity: FakeUser::class)]
    public mixed $user;
}
