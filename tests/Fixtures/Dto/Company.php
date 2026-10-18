<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Dto;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

#[Entity(name: 'companies')]
class Company
{
    #[Field]
    #[Identifier]
    public int $id;

    #[Field]
    public string $name;
}
