<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[Entity(name: 'things', repositoryClass: FakeRepository::class)]
class FakeEntityWithIdentifierNotFirst
{
    #[Field(mappedFrom: 'name')]
    public ?string $name = null;

    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public ?int $id = null;
}
