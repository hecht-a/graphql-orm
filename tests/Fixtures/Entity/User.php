<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Entity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[Entity(name: 'users', repositoryClass: FakeRepository::class)]
class User
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Field(mappedFrom: 'name')]
    public string $name;
}
