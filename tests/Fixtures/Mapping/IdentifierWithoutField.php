<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Mapping;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Identifier;

#[Entity(name: 'items')]
class IdentifierWithoutField
{
    #[Identifier]
    public int $id;
}
