<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Mapping;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Fetch;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

#[Entity(name: 'tasks')]
class MappedTask
{
    #[Field]
    #[Identifier]
    public int $id;

    #[Field]
    public string $title;

    #[Relation(fetch: Fetch::Lazy)]
    public ?MappedUser $user = null;

    #[Relation(mappedFrom: 'reviewer', targetEntity: MappedUser::class)]
    public ?object $reviewer = null;
}
