<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Cache;

use GraphqlOrm\Mapping\Cache;
use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

#[Entity(name: 'tasks')]
#[Cache(region: 'reference_data', lifetime: 600)]
class CachedTask
{
    #[Field]
    #[Identifier]
    public int $id;

    #[Field]
    public string $title;

    #[Field]
    public ?\DateTimeImmutable $dueDate = null;

    #[Relation]
    public ?CachedUser $assignee = null;
}
