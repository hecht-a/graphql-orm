<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

/**
 * Each property has the GraphQL name of the other field.
 */
#[Entity(name: 'items')]
class FakeEntityWithSwappedNames
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Field(mappedFrom: 'label')]
    public string $name;

    #[Field(mappedFrom: 'name')]
    public string $label;
}
