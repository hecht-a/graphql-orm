<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\PostGraphile\Entity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Attribute\Identifier;
use GraphqlOrm\Attribute\Relation;

/**
 * PostGraphile Relay connections (RelayDialect).
 */
#[GraphqlEntity(name: 'allUsers')]
class RelayUser
{
    #[GraphqlField(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[GraphqlField(mappedFrom: 'name')]
    public string $name;

    /** @var RelayTask[] */
    #[Relation(mappedFrom: 'tasksByUserId')]
    public array $tasks = [];
}
