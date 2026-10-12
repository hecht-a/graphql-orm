<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Repository;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeUserWithCollection;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NestedCollectionHydrationTest extends TestCase
{
    private const TASK_ROW = ['id' => 10, 'title' => 'Write tests'];

    #[Test]
    public function itHydratesANestedCollectionWithTheDefaultDialect(): void
    {
        $client = new FakeGraphqlClient([
            'data' => ['users' => [['id' => 1, 'tasks' => [self::TASK_ROW]]]],
        ]);
        $repository = $this->createRepository($client, new DefaultDialect());

        $users = $repository->findAll();

        $this->assertSingleTaskHydrated($users);
    }

    #[Test]
    public function itUnwrapsTheItemsEnvelopeOfANestedCollectionWithTheDataApiBuilderDialect(): void
    {
        $client = new FakeGraphqlClient([
            'data' => ['users' => ['items' => [['id' => 1, 'tasks' => ['items' => [self::TASK_ROW]]]]]],
        ]);
        $repository = $this->createRepository($client, new DataApiBuilderDialect());

        $users = $repository->findAll();

        $this->assertSingleTaskHydrated($users);
    }

    #[Test]
    public function itHydratesAnEmptyNestedCollectionWithTheDataApiBuilderDialect(): void
    {
        $client = new FakeGraphqlClient([
            'data' => ['users' => ['items' => [['id' => 1, 'tasks' => ['items' => []]]]]],
        ]);
        $repository = $this->createRepository($client, new DataApiBuilderDialect());

        $users = $repository->findAll();

        self::assertCount(1, $users);
        self::assertSame([], $users[0]->tasks);
    }

    /**
     * @return GraphqlEntityRepository<FakeUserWithCollection>
     */
    private function createRepository(FakeGraphqlClient $client, GraphqlQueryDialect $dialect): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        $manager = new GraphqlManager(
            $metadataFactory,
            $client,
            new EntityHydrator($metadataFactory),
            new GraphqlOrmDataCollector(),
            5,
            $dialect,
        );

        return new GraphqlEntityRepository($manager, FakeUserWithCollection::class);
    }

    /**
     * @param FakeUserWithCollection[] $users
     */
    private function assertSingleTaskHydrated(array $users): void
    {
        self::assertCount(1, $users);
        self::assertCount(1, $users[0]->tasks);
        self::assertInstanceOf(FakeTask::class, $users[0]->tasks[0] ?? null);
        self::assertSame(10, $users[0]->tasks[0]->id);
        self::assertSame('Write tests', $users[0]->tasks[0]->title);
    }
}
