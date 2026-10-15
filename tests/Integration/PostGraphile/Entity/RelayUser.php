<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\PostGraphile\Entity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

/**
 * PostGraphile Relay connections (RelayDialect).
 */
#[Entity(name: 'allUsers')]
class RelayUser
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Field(mappedFrom: 'name')]
    public string $name;

    /** @var RelayTask[] */
    #[Relation(mappedFrom: 'tasksByUserId')]
    public array $tasks = [];
}
