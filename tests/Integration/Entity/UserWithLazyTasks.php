<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Entity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Fetch;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

/**
 * The users with their tasks loaded on first access.
 */
#[Entity(name: 'users')]
class UserWithLazyTasks
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Field(mappedFrom: 'name')]
    public string $name;

    /** @var Task[] */
    #[Relation(mappedFrom: 'tasks', fetch: Fetch::Lazy)]
    public array $tasks = [];
}
