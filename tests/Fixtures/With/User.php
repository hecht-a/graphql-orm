<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\With;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

#[Entity(name: 'users')]
class User
{
    #[Field]
    #[Identifier]
    public ?int $id = null;

    #[Field]
    public ?string $name = null;

    #[Relation]
    public ?Company $company = null;
}
