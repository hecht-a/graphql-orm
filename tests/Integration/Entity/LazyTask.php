<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Entity;

use GraphqlOrm\Attribute\Fetch;
use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Attribute\Identifier;
use GraphqlOrm\Attribute\Relation;

/**
 * The tasks with their user loaded on first access.
 */
#[GraphqlEntity(name: 'tasks')]
class LazyTask
{
    #[GraphqlField(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[GraphqlField(mappedFrom: 'title')]
    public string $title;

    #[Relation(mappedFrom: 'user', fetch: Fetch::Lazy)]
    public ?User $user = null;
}
