<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

#[Entity(name: 'things', manager: 'billing')]
class FakeEntityOfUnknownManager
{
    #[Field]
    #[Identifier]
    public int $id;
}
