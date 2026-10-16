<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Cache;

use GraphqlOrm\Mapping\Cache;
use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

#[Entity(name: 'users')]
#[Cache]
class CachedUser
{
    #[Field]
    #[Identifier]
    public int $id;

    #[Field]
    public string $name;

    /** @var list<CachedTask> */
    #[Relation(targetEntity: CachedTask::class)]
    public array $tasks;
}
