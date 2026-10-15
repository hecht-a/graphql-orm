<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\PostGraphile\Entity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

/**
 * PostGraphile simple collections (DefaultDialect).
 */
#[Entity(name: 'allUsersList')]
class ListUser
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Field(mappedFrom: 'name')]
    public string $name;

    /** @var ListTask[] */
    #[Relation(mappedFrom: 'tasksByUserIdList')]
    public array $tasks = [];
}
