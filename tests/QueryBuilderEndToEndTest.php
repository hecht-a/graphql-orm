<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\Entity\Task;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\TestCase;

final class QueryBuilderEndToEndTest extends TestCase
{
    private function createManager(FakeGraphqlClient $client): GraphqlManager
    {
        return new GraphqlManager(
            new GraphqlEntityMetadataFactory(),
            $client,
            new EntityHydrator(new GraphqlEntityMetadataFactory()),
            $this->createStub(GraphqlOrmDataCollector::class),
            5
        );
    }

    public function testFilteringPaginationOrderingAndRelations(): void
    {
        $client = new FakeGraphqlClient([
            'data' => [
                'tasks' => [
                    'items' => [
                        [
                            'id' => 1,
                            'title' => 'User Task',
                            'user' => [
                                'id' => 10,
                                'name' => 'John',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $manager = $this->createManager($client);
        $manager->dialect = new DataApiBuilderDialect();

        $repo = new GraphqlEntityRepository($manager, Task::class);

        $qb = $repo->createQueryBuilder();
        $result = $qb
                ->select('id', 'title', 'user.name')
                ->where(
                    $qb->expr()->orX(
                        $qb->expr()->contains('title', 'User'),
                        $qb->expr()->eq('title', 'Task')
                    ))
                ->limit(10)
                ->orderBy('title', Direction::ASC)
                ->getQuery()
                ->getResult();

        self::assertCount(1, $result);

        $task = $result[0];

        self::assertSame(1, $task->id);
        self::assertSame('User Task', $task->title);
        self::assertSame('John', $task->user->name);

        $query = $client->lastQuery;

        self::assertNotEmpty($query);
        self::assertStringContainsString('filter:', $query);
        self::assertStringContainsString('or:', $query);
        self::assertStringContainsString('contains:', $query);
        self::assertStringContainsString('first: 10', $query);
        self::assertStringContainsString('orderBy:', $query);
        self::assertStringContainsString('user {', $query);
        self::assertStringContainsString('items {', $query);
    }
}
