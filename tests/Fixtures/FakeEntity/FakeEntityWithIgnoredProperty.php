<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[GraphqlEntity(name: 'entity', repositoryClass: FakeRepository::class)]
class FakeEntityWithIgnoredProperty
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    public string $ignored;
}
