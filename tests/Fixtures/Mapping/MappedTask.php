<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Mapping;

use GraphqlOrm\Attribute\Fetch;
use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Attribute\Identifier;
use GraphqlOrm\Attribute\Relation;

#[GraphqlEntity(name: 'tasks')]
class MappedTask
{
    #[GraphqlField]
    #[Identifier]
    public int $id;

    #[GraphqlField]
    public string $title;

    #[Relation(fetch: Fetch::Lazy)]
    public ?MappedUser $user = null;

    #[Relation(mappedFrom: 'reviewer', targetEntity: MappedUser::class)]
    public ?object $reviewer = null;
}
