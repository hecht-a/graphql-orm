<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Depth;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[GraphqlEntity(name: 'chainAs', repositoryClass: FakeRepository::class)]
class ChainA
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public ?int $id = null;

    #[GraphqlField(mappedFrom: 'label')]
    public ?string $label = null;

    #[GraphqlField(mappedFrom: 'next')]
    public ?ChainB $next = null;
}
