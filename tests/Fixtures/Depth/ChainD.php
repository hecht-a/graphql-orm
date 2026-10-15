<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Depth;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[Entity(name: 'chainDs', repositoryClass: FakeRepository::class)]
class ChainD
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public ?int $id = null;

    #[Field(mappedFrom: 'label')]
    public ?string $label = null;
}
