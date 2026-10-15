<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Attribute\Identifier;
use GraphqlOrm\Attribute\Relation;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[GraphqlEntity(name: 'users', repositoryClass: FakeRepository::class)]
class FakeUserWithCollection
{
    #[GraphqlField(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    /** @var FakeTask[] */
    #[Relation(mappedFrom: 'tasks', targetEntity: FakeTask::class)]
    public array $tasks;
}
