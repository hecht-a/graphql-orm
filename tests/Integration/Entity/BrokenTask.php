<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Entity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

/**
 * A deliberately wrong mapping of the tasks, for the schema validation tests: due_date is nullable in the database,
 * and a task has a single user.
 */
#[GraphqlEntity(name: 'tasks')]
class BrokenTask
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'due_date')]
    public \DateTimeImmutable $dueDate;

    /** @var User[] */
    #[GraphqlField(mappedFrom: 'user', targetEntity: User::class)]
    public array $user = [];
}
