<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeRepository;

use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\Task;

/**
 * @extends GraphqlEntityRepository<Task>
 */
class TaskRepository extends GraphqlEntityRepository
{
    public function __construct(GraphqlManager $graphQLManager)
    {
        parent::__construct($graphQLManager, Task::class);
    }
}
