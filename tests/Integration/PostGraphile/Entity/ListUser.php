<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\PostGraphile\Entity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Attribute\Identifier;
use GraphqlOrm\Attribute\Relation;

/**
 * PostGraphile simple collections (DefaultDialect).
 */
#[GraphqlEntity(name: 'allUsersList')]
class ListUser
{
    #[GraphqlField(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[GraphqlField(mappedFrom: 'name')]
    public string $name;

    /** @var ListTask[] */
    #[Relation(mappedFrom: 'tasksByUserIdList')]
    public array $tasks = [];
}
