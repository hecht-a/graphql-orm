<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\PHPStan\data;

use GraphqlOrm\Query\Direction;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Integration\Entity\Task;

/**
 * @param GraphqlEntityRepository<Task> $tasks
 */
function queryBuilderFieldNames(GraphqlEntityRepository $tasks, string $field): void
{
    $tasks->createQueryBuilder()->select('title', 'user.name')->addSelect('dueDate');
    $tasks->createQueryBuilder()->select('user.nmae');
    $tasks->createQueryBuilder()->select('title.length');
    $tasks->createQueryBuilder()->orderBy('dueDate', Direction::ASC);
    $tasks->createQueryBuilder()->orderBy('due_date', Direction::ASC)->addSelect($field);
}
