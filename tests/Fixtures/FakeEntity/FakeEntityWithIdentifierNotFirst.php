<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[GraphqlEntity(name: 'things', repositoryClass: FakeRepository::class)]
class FakeEntityWithIdentifierNotFirst
{
    #[GraphqlField(mappedFrom: 'name')]
    public ?string $name = null;

    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public ?int $id = null;
}
