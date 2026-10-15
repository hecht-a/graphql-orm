<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[Entity(name: 'tasks', repositoryClass: FakeRepository::class)]
class FakeTaskWithUnionRelation
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Relation(mappedFrom: 'user')]
    public FakeUser|\stdClass $user;
}
