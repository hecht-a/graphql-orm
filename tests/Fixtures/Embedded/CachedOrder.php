<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Embedded;

use GraphqlOrm\Mapping\Cache;
use GraphqlOrm\Mapping\Embedded;
use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

#[Entity(name: 'orders')]
#[Cache]
class CachedOrder
{
    #[Field]
    #[Identifier]
    public int $id;

    #[Embedded]
    public ?Money $price = null;

    #[Embedded(prefix: 'total_')]
    public ?Money $total = null;
}
