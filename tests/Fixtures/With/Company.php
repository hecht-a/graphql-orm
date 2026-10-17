<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\With;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

#[Entity(name: 'companies')]
class Company
{
    #[Field]
    #[Identifier]
    public ?int $id = null;

    #[Field]
    public ?string $name = null;
}
