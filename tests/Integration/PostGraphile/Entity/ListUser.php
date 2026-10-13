<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\PostGraphile\Entity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

/**
 * PostGraphile simple collections (DefaultDialect).
 */
#[GraphqlEntity(name: 'allUsersList')]
class ListUser
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'name')]
    public string $name;

    /** @var ListTask[] */
    #[GraphqlField(mappedFrom: 'tasksByUserIdList')]
    public array $tasks = [];
}
