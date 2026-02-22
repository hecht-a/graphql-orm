<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Entity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Tests\Fixtures\Repository\FakeRepository;

#[GraphqlEntity(name: 'users', repositoryClass: FakeRepository::class)]
class User
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'name')]
    public string $name;
}
