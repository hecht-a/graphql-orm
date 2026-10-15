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
#[GraphqlEntity(name: 'allTasksList')]
class ListTask
{
    #[GraphqlField(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[GraphqlField(mappedFrom: 'title')]
    public string $title;

    #[GraphqlField(mappedFrom: 'done')]
    public bool $done;

    #[GraphqlField(mappedFrom: 'dueDate')]
    public ?\DateTimeImmutable $dueDate = null;

    #[Relation(mappedFrom: 'userByUserId')]
    public ?ListUser $user = null;
}
