<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\With;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Fetch;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

#[Entity(name: 'tasks')]
class Task
{
    #[Field]
    #[Identifier]
    public ?int $id = null;

    #[Field]
    public ?string $title = null;

    #[Relation]
    public ?User $assignee = null;

    #[Relation(mappedFrom: 'tag')]
    public ?Label $label = null;

    #[Relation(fetch: Fetch::Lazy)]
    public ?User $reviewer = null;
}
