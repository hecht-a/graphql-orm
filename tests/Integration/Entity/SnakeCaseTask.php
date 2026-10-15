<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Entity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

/**
 * The tasks mapped without "mappedFrom", named by SnakeCaseNamingStrategy.
 */
#[Entity(name: 'tasks')]
class SnakeCaseTask
{
    #[Field]
    #[Identifier]
    public int $id;

    #[Field]
    public string $title;

    #[Field]
    public ?\DateTimeImmutable $dueDate = null;

    #[Relation]
    public ?User $user = null;
}
