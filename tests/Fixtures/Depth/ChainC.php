<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Depth;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Attribute\Identifier;
use GraphqlOrm\Attribute\Relation;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[GraphqlEntity(name: 'chainCs', repositoryClass: FakeRepository::class)]
class ChainC
{
    #[GraphqlField(mappedFrom: 'id')]
    #[Identifier]
    public ?int $id = null;

    #[GraphqlField(mappedFrom: 'label')]
    public ?string $label = null;

    #[Relation(mappedFrom: 'next')]
    public ?ChainD $next = null;
}
