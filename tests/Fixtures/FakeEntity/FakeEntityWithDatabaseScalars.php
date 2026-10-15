<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

#[Entity(name: 'invoices')]
class FakeEntityWithDatabaseScalars
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Field(mappedFrom: 'issuedAt')]
    public ?\DateTimeImmutable $issuedAt = null;

    #[Field(mappedFrom: 'amount')]
    public ?float $amount = null;

    #[Field(mappedFrom: 'lines')]
    public ?int $lines = null;
}
