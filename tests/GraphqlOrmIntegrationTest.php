<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\Entity\Task;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\TestCase;

final class GraphqlOrmIntegrationTest extends TestCase
{
    public function testFindAllEndToEnd(): void
    {
        $client = new FakeGraphqlClient([
            'data' => [
                'tasks' => [
                    [
                        'id' => 1,
                        'title' => 'Task 1',
                        'user' => [
                            'id' => 10,
                            'name' => 'John',
                        ],
                    ],
                ],
            ],
        ]);

        $manager = new GraphqlManager(
            new GraphqlEntityMetadataFactory(),
            $client,
            new EntityHydrator(new GraphqlEntityMetadataFactory()),
            $this->createStub(GraphqlOrmDataCollector::class),
            3
        );

        $repository = new GraphqlEntityRepository($manager, Task::class);

        $result = $repository->findAll();

        self::assertCount(1, $result);

        $task = $result[0];

        self::assertSame(1, $task->id);
        self::assertSame('Task 1', $task->title);

        self::assertSame('John', $task->user->name);
    }

    public function testQueryBuilderEndToEnd(): void
    {
        $client = new FakeGraphqlClient([
            'data' => [
                'tasks' => [
                    [
                        'id' => 2,
                        'title' => 'Filtered',
                    ],
                ],
            ],
        ]);

        $manager = new GraphqlManager(
            new GraphqlEntityMetadataFactory(),
            $client,
            new EntityHydrator(new GraphqlEntityMetadataFactory()),
            $this->createStub(GraphqlOrmDataCollector::class),
            3
        );

        $repo = new GraphqlEntityRepository($manager, Task::class);

        $result = $repo
            ->createQueryBuilder()
            ->select('title')
            ->where('id', 2)
            ->getQuery()
            ->getResult();

        self::assertSame('Filtered', $result[0]->title);
    }
}
