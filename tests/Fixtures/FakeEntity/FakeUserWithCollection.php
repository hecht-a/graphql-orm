<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[Entity(name: 'users', repositoryClass: FakeRepository::class)]
class FakeUserWithCollection
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    /** @var FakeTask[] */
    #[Relation(mappedFrom: 'tasks', targetEntity: FakeTask::class)]
    public array $tasks;
}
