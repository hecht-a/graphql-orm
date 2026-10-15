<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Depth;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Attribute\Identifier;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[GraphqlEntity(name: 'chainDs', repositoryClass: FakeRepository::class)]
class ChainD
{
    #[GraphqlField(mappedFrom: 'id')]
    #[Identifier]
    public ?int $id = null;

    #[GraphqlField(mappedFrom: 'label')]
    public ?string $label = null;
}
