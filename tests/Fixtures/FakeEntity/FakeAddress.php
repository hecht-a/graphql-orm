<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[GraphqlEntity(name: 'addresses', repositoryClass: FakeRepository::class)]
class FakeAddress
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public ?int $id = null;

    #[GraphqlField(mappedFrom: 'city')]
    public ?string $city = null;
}
