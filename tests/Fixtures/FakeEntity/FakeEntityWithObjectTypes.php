<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[GraphqlEntity(name: 'events', repositoryClass: FakeRepository::class)]
class FakeEntityWithObjectTypes
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public ?int $id = null;

    #[GraphqlField(mappedFrom: 'status')]
    public ?FakeStatus $status = null;

    #[GraphqlField(mappedFrom: 'createdAt')]
    public ?\DateTime $createdAt = null;

    #[GraphqlField(mappedFrom: 'updatedAt')]
    public ?\DateTimeInterface $updatedAt = null;
}
