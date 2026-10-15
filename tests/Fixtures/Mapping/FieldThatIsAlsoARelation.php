<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Mapping;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Relation;

#[Entity(name: 'items')]
class FieldThatIsAlsoARelation
{
    #[Field]
    #[Relation]
    public ?MappedUser $user = null;
}
