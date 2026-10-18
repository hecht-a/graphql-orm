<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Embedded;

use GraphqlOrm\Mapping\Embedded;
use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

#[Entity(name: 'orders')]
class Order
{
    #[Field]
    #[Identifier]
    public int $id;

    /** A GraphQL object: price { amount currency } */
    #[Embedded]
    public ?Money $price = null;

    /** Flat fields: total_amount, total_currency */
    #[Embedded(prefix: 'total_')]
    public ?Money $total = null;
}
