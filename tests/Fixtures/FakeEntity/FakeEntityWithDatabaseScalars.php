<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

#[GraphqlEntity(name: 'invoices')]
class FakeEntityWithDatabaseScalars
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'issuedAt')]
    public ?\DateTimeImmutable $issuedAt = null;

    #[GraphqlField(mappedFrom: 'amount')]
    public ?float $amount = null;

    #[GraphqlField(mappedFrom: 'lines')]
    public ?int $lines = null;
}
