<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\PHPStan\data;

use GraphqlOrm\Query\Direction;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Integration\Entity\Task;

/**
 * @param GraphqlEntityRepository<Task> $tasks
 * @param array<string, mixed>          $criteria
 */
function repositoryFieldNames(GraphqlEntityRepository $tasks, array $criteria): void
{
    $tasks->findBy(['title' => 'A', 'done' => true]);
    $tasks->findBy(['dueDate' => null]);
    $tasks->findBy(['titel' => 'A']);
    $tasks->findOneBy([], ['due_date' => Direction::ASC]);
    $tasks->findBy([], ['dueDate' => Direction::ASC]);
    $tasks->findOneBy(['user_id' => 1]);
    $tasks->findBy($criteria);
}
