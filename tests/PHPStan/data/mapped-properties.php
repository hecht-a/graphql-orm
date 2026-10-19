<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\PHPStan\Data;

use GraphqlOrm\Mapping as GraphqlOrm;

#[GraphqlOrm\Entity(name: 'notes')]
class MappedNote
{
    #[GraphqlOrm\Field]
    #[GraphqlOrm\Identifier]
    public readonly int $id;

    #[GraphqlOrm\Relation]
    public readonly ?MappedNote $parent;

    public readonly string $notMapped;
}
