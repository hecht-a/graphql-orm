<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\AfterHydrate;
use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[Entity(name: 'products', repositoryClass: FakeRepository::class)]
class FakeEntityWithAfterHydrate
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Field(mappedFrom: 'price')]
    public float $price;

    #[Field(mappedFrom: 'taxRate')]
    public float $taxRate;

    public float $priceWithTax = 0.0;

    public int $afterHydrateCallCount = 0;

    #[AfterHydrate]
    public function computePriceWithTax(): void
    {
        $this->priceWithTax = $this->price * (1 + $this->taxRate / 100);
        ++$this->afterHydrateCallCount;
    }
}
