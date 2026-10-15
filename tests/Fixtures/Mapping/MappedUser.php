<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Mapping;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

#[Entity(name: 'users')]
class MappedUser
{
    #[Field(mappedFrom: 'user_id')]
    #[Identifier]
    public int $id;

    /** @var MappedTask[] */
    #[Relation]
    public array $tasks = [];
}
