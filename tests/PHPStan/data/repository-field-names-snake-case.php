<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\PHPStan\data;

use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityNamedByStrategy;

/**
 * @param GraphqlEntityRepository<FakeEntityNamedByStrategy> $tasks
 */
function repositoryFieldNamesInSnakeCase(GraphqlEntityRepository $tasks): void
{
    $tasks->findBy(['due_date' => null, 'dueDate' => null, 'assigned_user' => 1]);
    $tasks->findBy(['due_dat' => null]);
}
