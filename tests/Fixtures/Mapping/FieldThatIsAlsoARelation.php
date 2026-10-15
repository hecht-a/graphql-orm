<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Mapping;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Attribute\Relation;

#[GraphqlEntity(name: 'items')]
class FieldThatIsAlsoARelation
{
    #[GraphqlField]
    #[Relation]
    public ?MappedUser $user = null;
}
