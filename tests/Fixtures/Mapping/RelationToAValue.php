<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Mapping;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Relation;

#[Entity(name: 'items')]
class RelationToAValue
{
    #[Relation]
    public string $title;
}
