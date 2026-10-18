<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Converter;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

#[Entity(name: 'products')]
class ProductWithUnregisteredConverter
{
    #[Field]
    #[Identifier]
    public int $id;

    #[Field(converter: ConfiguredConverter::class)]
    public ?string $name = null;
}
