<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\AfterHydrate;
use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[GraphqlEntity(name: 'products', repositoryClass: FakeRepository::class)]
class FakeEntityWithMultipleAfterHydrate
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'price')]
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
