<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Converter;

use GraphqlOrm\Mapping\Cache;
use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

#[Entity(name: 'products')]
#[Cache]
class CachedProduct
{
    #[Field]
    #[Identifier]
    public int $id;

    #[Field]
    public ?Money $price = null;

    #[Field(converter: DecimalMoneyConverter::class)]
    public ?Money $discount = null;
}
