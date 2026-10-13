<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Benchmark;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Integration\Entity\Task;
use PhpBench\Attributes as Bench;

/**
 * Hydration of a large response: 1,000 tasks, each with its user and the user's tasks reduced to identifiers.
 */
#[Bench\BeforeMethods('setUp')]
final class HydrationBench
{
    private const ROWS = 1000;

    /** @var GraphqlEntityRepository<Task> */
    private GraphqlEntityRepository $tasks;

    public function setUp(): void
    {
        $rows = [];

        for ($id = 1; $id <= self::ROWS; ++$id) {
            $user = $id % 10 + 1;
            $rows[] = [
                'id' => $id,
                'title' => 'Task ' . $id,
                'done' => $id % 2 === 0,
                'due_date' => '2026-01-15T10:00:00+00:00',
                'user' => ['id' => $user, 'name' => 'User ' . $user, 'tasks' => ['items' => [['id' => $id]]]],
            ];
        }

        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, new StaticGraphqlClient(['data' => ['tasks' => ['items' => $rows]]]), new EntityHydrator($metadataFactory), null, 2, new DataApiBuilderDialect());
        $this->tasks = new GraphqlEntityRepository($manager, Task::class);
    }

    public function benchHydrateAThousandRows(): void
    {
        $this->tasks->findAll();
    }
}
