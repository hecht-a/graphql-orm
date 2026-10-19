<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Repository;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Execution\IdentityMap;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\GraphqlQuery;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Integration\Entity\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * With a shared identity map, the queries of a request share their entities, and find() serves a known one.
 */
final class SharedIdentityMapTest extends TestCase
{
    private const TASK_A = ['id' => 1, 'title' => 'A', 'done' => false, 'due_date' => null, 'user' => null];

    #[Test]
    public function itGivesTheSameInstanceToEveryQuery(): void
    {
        $tasks = $this->tasks(new FakeGraphqlClient(self::response([self::TASK_A]), self::response([self::TASK_A])), new IdentityMap());

        self::assertSame($tasks->findAll()[0], $tasks->findBy(['done' => false])[0]);
    }

    #[Test]
    public function itGivesTheSameInstanceToQueriesSentTogether(): void
    {
        $tasks = $this->tasks(new FakeGraphqlClient(self::response([self::TASK_A]), self::response([self::TASK_A])), new IdentityMap());

        [$all, $notDone] = GraphqlQuery::concurrently($tasks->createQueryBuilder()->getQuery(), $tasks->createQueryBuilder()->getQuery());

        self::assertIsArray($all);
        self::assertIsArray($notDone);
        self::assertSame($all[0], $notDone[0]);
    }

    #[Test]
    public function itKeepsEveryQueryApartWithoutSharedIdentityMap(): void
    {
        $tasks = $this->tasks(new FakeGraphqlClient(self::response([self::TASK_A]), self::response([self::TASK_A])));

        self::assertNotSame($tasks->findAll()[0], $tasks->findAll()[0]);
    }

    #[Test]
    public function itServesFindFromTheIdentityMapWithoutQuery(): void
    {
        $client = new FakeGraphqlClient(self::response([self::TASK_A]));
        $tasks = $this->tasks($client, new IdentityMap());

        $loaded = $tasks->findAll()[0];

        self::assertSame($loaded, $tasks->find(1));
        self::assertSame($loaded, $tasks->find('1'));
        self::assertSame(1, $client->callCount);
    }

    #[Test]
    public function itRecordsTheEntitiesServedWithoutQueryInTheProfiler(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $tasks = $this->tasks(new FakeGraphqlClient(self::response([self::TASK_A])), new IdentityMap(), collector: $collector);
        $tasks->findAll();

        $tasks->find(1);
        $tasks->find(1);

        $hits = $collector->getIdentityMapHits();
        self::assertSame(2, $collector->getIdentityMapHitCount());
        self::assertSame([Task::class, 1, 2], [$hits[0]['class'], $hits[0]['identifier'], $hits[0]['count']]);
        self::assertCount(2, $hits[0]['callers']);
        self::assertStringContainsString('SharedIdentityMapTest.php:', $hits[0]['callers'][0]);
    }

    #[Test]
    public function itQueriesAnEntityKnownOnlyPartially(): void
    {
        $client = new FakeGraphqlClient(self::response([['id' => 1, 'title' => 'A']]), self::response([self::TASK_A]));
        $tasks = $this->tasks($client, new IdentityMap());

        $partial = $tasks->createQueryBuilder()->select('title')->getQuery()->getResult();
        $task = $tasks->find(1);

        self::assertSame(2, $client->callCount);
        self::assertIsArray($partial);
        self::assertSame($partial[0], $task);
        self::assertFalse($task->done);
    }

    #[Test]
    public function itUpdatesTheKnownInstanceWithTheLatestValues(): void
    {
        $tasks = $this->tasks(new FakeGraphqlClient(self::response([self::TASK_A]), self::response([[...self::TASK_A, 'title' => 'B']])), new IdentityMap());

        $first = $tasks->findAll()[0];
        $tasks->findAll();

        self::assertSame('B', $first->title);
    }

    #[Test]
    public function itQueriesAgainOnceReset(): void
    {
        $client = new FakeGraphqlClient(self::response([self::TASK_A]), self::response([self::TASK_A]));
        $identityMap = new IdentityMap();
        $tasks = $this->tasks($client, $identityMap);

        $first = $tasks->findAll()[0];
        $identityMap->reset();

        self::assertNotSame($first, $tasks->find(1));
        self::assertSame(2, $client->callCount);
    }

    #[Test]
    public function itDoesNotKeepTheEntitiesOfAnIteration(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['tasks' => ['items' => [self::TASK_A], 'hasNextPage' => false, 'endCursor' => 'c1']]],
            self::response([self::TASK_A]),
        );
        $tasks = $this->tasks($client, new IdentityMap());

        iterator_to_array($tasks->createQueryBuilder()->limit(10)->paginate()->getQuery()->toIterable());
        $tasks->find(1);

        self::assertSame(2, $client->callCount);
    }

    #[Test]
    public function itLoadsALazyRelationWithTheSharedIdentityMap(): void
    {
        $client = new FakeGraphqlClient(
            self::response([[...self::TASK_A, 'user' => ['id' => 7]]]),
            ['data' => ['users' => ['items' => [['id' => 7, 'name' => 'User A', 'tasks' => ['items' => []]]]]]],
        );
        $tasks = $this->tasks($client, new IdentityMap(), lazyLoading: true, maxDepth: 0);

        $task = $tasks->findAll()[0];

        self::assertSame('User A', $task->user?->name);
        self::assertSame(2, $client->callCount);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private static function response(array $rows): array
    {
        return ['data' => ['tasks' => ['items' => $rows]]];
    }

    /**
     * @return GraphqlEntityRepository<Task>
     */
    private function tasks(FakeGraphqlClient $client, ?IdentityMap $identityMap = null, bool $lazyLoading = false, int $maxDepth = 2, ?GraphqlOrmDataCollector $collector = null): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager(
            $metadataFactory,
            $client,
            new EntityHydrator($metadataFactory),
            $collector,
            $maxDepth,
            new DataApiBuilderDialect(),
            lazyLoading: $lazyLoading,
            identityMap: $identityMap,
        );

        return new GraphqlEntityRepository($manager, Task::class);
    }
}
