<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Strict;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

#[Entity(name: 'strictUsers')]
class StrictUser
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Field(mappedFrom: 'name')]
    public ?string $name = null;
}
