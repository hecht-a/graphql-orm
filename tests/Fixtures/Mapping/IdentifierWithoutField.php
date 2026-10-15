<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Mapping;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\Identifier;

#[GraphqlEntity(name: 'items')]
class IdentifierWithoutField
{
    #[Identifier]
    public int $id;
}
