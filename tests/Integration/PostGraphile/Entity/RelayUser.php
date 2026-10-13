<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\PostGraphile\Entity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

/**
 * PostGraphile Relay connections (RelayDialect).
 */
#[GraphqlEntity(name: 'allUsers')]
class RelayUser
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'name')]
    public string $name;

    /** @var RelayTask[] */
    #[GraphqlField(mappedFrom: 'tasksByUserId')]
    public array $tasks = [];
}
