<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Entity;

use GraphqlOrm\Attribute\Fetch;
use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

/**
 * The users with their tasks loaded on first access.
 */
#[GraphqlEntity(name: 'users')]
class UserWithLazyTasks
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'name')]
    public string $name;

    /** @var Task[] */
    #[GraphqlField(mappedFrom: 'tasks', fetch: Fetch::Lazy)]
    public array $tasks = [];
}
