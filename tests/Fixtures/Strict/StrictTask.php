<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Strict;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

#[Entity(name: 'strictTasks')]
class StrictTask
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Field(mappedFrom: 'title')]
    public string $title;

    #[Relation(mappedFrom: 'owner')]
    public ?StrictUser $owner = null;

    /** @var StrictUser[] */
    #[Relation(mappedFrom: 'members', targetEntity: StrictUser::class)]
    public array $members = [];
}
