<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\AfterHydrate;
use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[GraphqlEntity(name: 'products', repositoryClass: FakeRepository::class)]
class FakeEntityWithAfterHydrate
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'price')]
    public float $price;

    #[GraphqlField(mappedFrom: 'taxRate')]
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
