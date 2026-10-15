<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Entity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Attribute\Identifier;
use GraphqlOrm\Attribute\Relation;

/**
 * The tasks mapped without "mappedFrom", named by SnakeCaseNamingStrategy.
 */
#[GraphqlEntity(name: 'tasks')]
class SnakeCaseTask
{
    #[GraphqlField]
    #[Identifier]
    public int $id;

    #[GraphqlField]
    public string $title;

    #[GraphqlField]
    public ?\DateTimeImmutable $dueDate = null;

    #[Relation]
    public ?User $user = null;
}
