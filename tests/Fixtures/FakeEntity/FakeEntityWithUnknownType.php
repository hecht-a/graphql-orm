<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[Entity(name: 'ghosts', repositoryClass: FakeRepository::class)]
class FakeEntityWithUnknownType
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;
}
