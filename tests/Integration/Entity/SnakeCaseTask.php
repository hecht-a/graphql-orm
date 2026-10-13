<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Entity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

/**
 * The tasks mapped without "mappedFrom", named by SnakeCaseNamingStrategy.
 */
#[GraphqlEntity(name: 'tasks')]
class SnakeCaseTask
{
    #[GraphqlField(identifier: true)]
    public int $id;

    #[GraphqlField]
    public string $title;

    #[GraphqlField]
    public ?\DateTimeImmutable $dueDate = null;

    #[GraphqlField]
    public ?User $user = null;
}
