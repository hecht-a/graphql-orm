<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[GraphqlEntity(name: 'scalars', repositoryClass: FakeRepository::class)]
class FakeEntityWithScalars
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public ?int $id = null;

    #[GraphqlField(mappedFrom: 'price')]
    public ?float $price = null;

    #[GraphqlField(mappedFrom: 'active')]
    public ?bool $active = null;

    #[GraphqlField(mappedFrom: 'label')]
    public ?string $label = null;
}
