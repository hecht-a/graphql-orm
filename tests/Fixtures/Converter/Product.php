<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Converter;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

#[Entity(name: 'products')]
class Product
{
    #[Field]
    #[Identifier]
    public int $id;

    #[Field]
    public string $name;

    #[Field]
    public ?Money $price = null;

    #[Field(converter: DecimalMoneyConverter::class)]
    public ?Money $discount = null;

    #[Field]
    public ?Email $contact = null;
}
