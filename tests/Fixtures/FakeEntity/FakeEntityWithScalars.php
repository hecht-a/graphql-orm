<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[Entity(name: 'scalars', repositoryClass: FakeRepository::class)]
class FakeEntityWithScalars
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public ?int $id = null;

    #[Field(mappedFrom: 'price')]
    public ?float $price = null;

    #[Field(mappedFrom: 'active')]
    public ?bool $active = null;

    #[Field(mappedFrom: 'label')]
    public ?string $label = null;
}
