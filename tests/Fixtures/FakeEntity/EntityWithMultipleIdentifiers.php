<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[GraphqlEntity(name: 'bad', repositoryClass: FakeRepository::class)]
class EntityWithMultipleIdentifiers
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'uuid', identifier: true)]
    public string $uuid;
}
