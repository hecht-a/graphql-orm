<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Mapping;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Attribute\Identifier;
use GraphqlOrm\Attribute\Relation;

#[GraphqlEntity(name: 'users')]
class MappedUser
{
    #[GraphqlField(mappedFrom: 'user_id')]
    #[Identifier]
    public int $id;

    /** @var MappedTask[] */
    #[Relation]
    public array $tasks = [];
}
