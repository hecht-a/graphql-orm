<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[Entity(name: 'events', repositoryClass: FakeRepository::class)]
class FakeEntityWithObjectTypes
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public ?int $id = null;

    #[Field(mappedFrom: 'status')]
    public ?FakeStatus $status = null;

    #[Field(mappedFrom: 'createdAt')]
    public ?\DateTime $createdAt = null;

    #[Field(mappedFrom: 'updatedAt')]
    public ?\DateTimeInterface $updatedAt = null;
}
