<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Strict;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

#[GraphqlEntity(name: 'strictUsers')]
class StrictUser
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'name')]
    public ?string $name = null;
}
