<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\PostGraphile\Entity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

/**
 * PostGraphile simple collections (DefaultDialect).
 */
#[GraphqlEntity(name: 'allTasksList')]
class ListTask
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'title')]
    public string $title;

    #[GraphqlField(mappedFrom: 'done')]
    public bool $done;

    #[GraphqlField(mappedFrom: 'dueDate')]
    public ?\DateTimeImmutable $dueDate = null;

    #[GraphqlField(mappedFrom: 'userByUserId')]
    public ?ListUser $user = null;
}
