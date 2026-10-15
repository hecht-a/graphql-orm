<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Attribute\Identifier;

#[GraphqlEntity(name: 'invoices')]
class FakeEntityWithDatabaseScalars
{
    #[GraphqlField(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[GraphqlField(mappedFrom: 'issuedAt')]
    public ?\DateTimeImmutable $issuedAt = null;

    #[GraphqlField(mappedFrom: 'amount')]
    public ?float $amount = null;

    #[GraphqlField(mappedFrom: 'lines')]
    public ?int $lines = null;
}
