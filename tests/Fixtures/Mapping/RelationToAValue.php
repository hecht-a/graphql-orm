<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Mapping;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\Relation;

#[GraphqlEntity(name: 'items')]
class RelationToAValue
{
    #[Relation]
    public string $title;
}
