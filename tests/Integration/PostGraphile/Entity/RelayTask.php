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
#[Entity(name: 'allTasks')]
class RelayTask
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Field(mappedFrom: 'title')]
    public string $title;

    #[Field(mappedFrom: 'done')]
    public bool $done;

    #[Field(mappedFrom: 'dueDate')]
    public ?\DateTimeImmutable $dueDate = null;

    #[Relation(mappedFrom: 'userByUserId')]
    public ?RelayUser $user = null;
}
