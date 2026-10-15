<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\AfterHydrate;
use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[Entity(name: 'products', repositoryClass: FakeRepository::class)]
class FakeEntityWithMultipleAfterHydrate
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Field(mappedFrom: 'price')]
    public float $price;

    public int $callCount = 0;

    #[AfterHydrate]
    public function firstHook(): void
    {
        ++$this->callCount;
    }

    #[AfterHydrate]
    public function secondHook(): void
    {
        ++$this->callCount;
    }
}
