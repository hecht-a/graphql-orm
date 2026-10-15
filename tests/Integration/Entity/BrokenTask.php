<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Entity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

/**
 * A deliberately wrong mapping of the tasks, for the schema validation tests: due_date is nullable in the database,
 * and a task has a single user.
 */
#[Entity(name: 'tasks')]
class BrokenTask
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Field(mappedFrom: 'due_date')]
    public \DateTimeImmutable $dueDate;

    /** @var User[] */
    #[Relation(mappedFrom: 'user', targetEntity: User::class)]
    public array $user = [];
}
