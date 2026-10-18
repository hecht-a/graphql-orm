<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Cache;

use GraphqlOrm\Cache\GraphqlCache;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;

/**
 * A query whose result cache is enabled is answered from the cache pool while its response is kept there: the response is
 * stored as received and hydrated again, as Doctrine Query::enableResultCache().
 */
final class ResultCacheTest extends TestCase
{
    private const TASKS = ['data' => ['tasks' => [['id' => 1, 'title' => 'A'], ['id' => 2, 'title' => 'B']]]];

    private ArrayAdapter $pool;

    private GraphqlOrmDataCollector $collector;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->collector = new GraphqlOrmDataCollector();
    }

    #[Test]
    public function itAnswersTheSameQueryFromTheCache(): void
    {
        $client = new FakeGraphqlClient(self::TASKS, self::TASKS);
        $tasks = $this->repository($client);

        $tasks->createQueryBuilder()->getQuery()->enableResultCache()->getResult();
        $result = $tasks->createQueryBuilder()->getQuery()->enableResultCache()->getResult();

        self::assertSame(1, $client->callCount);
        self::assertIsArray($result);
        self::assertSame(['A', 'B'], array_map(static fn (FakeTask $task) => $task->title, $result));
    }

    #[Test]
    public function itSendsTheQueryWhenItsResultCacheIsNotEnabled(): void
    {
        $client = new FakeGraphqlClient(self::TASKS, self::TASKS);
        $tasks = $this->repository($client);

        $tasks->createQueryBuilder()->getQuery()->enableResultCache()->getResult();
        $tasks->createQueryBuilder()->getQuery()->getResult();

        self::assertSame(2, $client->callCount);
    }

    #[Test]
    public function itKeepsTheResultsOfQueriesWithOtherValuesApart(): void
    {
        $client = new FakeGraphqlClient(self::TASKS, self::TASKS);
        $tasks = $this->repository($client);

        $tasks->createQueryBuilder()->where($tasks->createQueryBuilder()->expr()->eq('title', 'A'))->getQuery()->enableResultCache()->getResult();
        $tasks->createQueryBuilder()->where($tasks->createQueryBuilder()->expr()->eq('title', 'B'))->getQuery()->enableResultCache()->getResult();

        self::assertSame(2, $client->callCount);
    }

    #[Test]
    public function itDoesNotKeepAResponseWithErrors(): void
    {
        $failed = ['data' => ['tasks' => []], 'errors' => [['message' => 'Unavailable']]];
        $client = new FakeGraphqlClient($failed, self::TASKS);
        $tasks = $this->repository($client);

        try {
            $tasks->createQueryBuilder()->getQuery()->enableResultCache()->getResult();
        } catch (\Throwable) {
        }
        $tasks->createQueryBuilder()->getQuery()->enableResultCache()->getResult();

        self::assertSame(2, $client->callCount);
    }

    #[Test]
    public function itKeepsTheResultForItsLifetime(): void
    {
        $tasks = $this->repository(new FakeGraphqlClient(self::TASKS));

        $before = microtime(true);
        $tasks->createQueryBuilder()->getQuery()->enableResultCache(lifetime: 300, key: 'tasks')->getResult();

        $expiry = $this->expiry('tasks');
        self::assertGreaterThanOrEqual($before + 300, $expiry);
        self::assertLessThanOrEqual(microtime(true) + 300, $expiry);
    }

    #[Test]
    public function itEvictsAResultByItsKey(): void
    {
        $client = new FakeGraphqlClient(self::TASKS, self::TASKS);
        $tasks = $this->repository($client);
        $tasks->createQueryBuilder()->getQuery()->enableResultCache(key: 'tasks')->getResult();

        (new GraphqlCache($this->pool))->evictQueryResult('tasks');
        $tasks->createQueryBuilder()->getQuery()->enableResultCache(key: 'tasks')->getResult();

        self::assertSame(2, $client->callCount);
    }

    #[Test]
    public function itEvictsEveryResultOfTheManager(): void
    {
        $client = new FakeGraphqlClient(self::TASKS, self::TASKS, self::TASKS, self::TASKS);
        $manager = $this->manager($client, new DefaultDialect());
        $tasks = new GraphqlEntityRepository($manager, FakeTask::class);
        $tasks->createQueryBuilder()->getQuery()->enableResultCache(key: 'tasks')->getResult();
        $tasks->createQueryBuilder()->limit(1)->getQuery()->enableResultCache()->getResult();

        self::assertSame(1, $manager->getCache()->evictQueryResults());
        $tasks->createQueryBuilder()->getQuery()->enableResultCache(key: 'tasks')->getResult();
        $tasks->createQueryBuilder()->limit(1)->getQuery()->enableResultCache()->getResult();

        self::assertSame(4, $client->callCount);
    }

    #[Test]
    public function itEvictsTheResultsOfOneManagerOfASharedPool(): void
    {
        $mainClient = new FakeGraphqlClient(self::TASKS, self::TASKS);
        $crmClient = new FakeGraphqlClient(self::TASKS, self::TASKS);
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $main = new GraphqlManager($metadataFactory, $mainClient, new EntityHydrator($metadataFactory), null, 1, cache: new GraphqlCache($this->pool, namespace: 'main'));
        $crm = new GraphqlManager($metadataFactory, $crmClient, new EntityHydrator($metadataFactory), null, 1, cache: new GraphqlCache($this->pool, namespace: 'crm'));
        $query = static fn (GraphqlManager $manager) => (new GraphqlEntityRepository($manager, FakeTask::class))->createQueryBuilder()->getQuery()->enableResultCache()->getResult();
        $query($main);
        $query($crm);

        $main->getCache()->evictQueryResults();
        $query($main);
        $query($crm);

        self::assertSame([2, 1], [$mainClient->callCount, $crmClient->callCount]);
    }

    #[Test]
    public function itSeesTheResultsEvictedByAnotherProcessFromItsNextRequest(): void
    {
        $client = new FakeGraphqlClient(self::TASKS, self::TASKS);
        $manager = $this->manager($client, new DefaultDialect());
        $tasks = new GraphqlEntityRepository($manager, FakeTask::class);
        $tasks->createQueryBuilder()->getQuery()->enableResultCache()->getResult();

        (new GraphqlCache($this->pool))->evictQueryResults();
        $manager->getCache()->reset();
        $tasks->createQueryBuilder()->getQuery()->enableResultCache()->getResult();

        self::assertSame(2, $client->callCount);
    }

    #[Test]
    public function itKeepsEachPageApartUnderAGivenKey(): void
    {
        $page = static fn (int $id, bool $next) => ['data' => ['tasks' => [
            'items' => [['id' => $id, 'title' => 'Task ' . $id]],
            'hasNextPage' => $next,
            'endCursor' => 'cursor-' . $id,
        ]]];
        $client = new FakeGraphqlClient($page(1, true), $page(2, false));
        $manager = $this->manager($client, new DataApiBuilderDialect());

        $titles = [];
        foreach ((new GraphqlEntityRepository($manager, FakeTask::class))->createQueryBuilder()->paginate()->limit(1)->getQuery()->enableResultCache(key: 'tasks')->toIterable() as $task) {
            self::assertInstanceOf(FakeTask::class, $task);
            $titles[] = $task->title;
        }

        self::assertSame(['Task 1', 'Task 2'], $titles);
    }

    #[Test]
    public function itTellsTheProfilerTheQueriesAnsweredByTheCache(): void
    {
        $tasks = $this->repository(new FakeGraphqlClient(self::TASKS));

        $tasks->createQueryBuilder()->getQuery()->enableResultCache()->getResult();
        $tasks->createQueryBuilder()->getQuery()->enableResultCache()->getResult();

        self::assertSame(['miss', 'hit'], array_column($this->collector->getQueries(), 'result_cache'));
    }

    #[Test]
    public function itKeepsTheResultsOfTwoApisApartInASharedPool(): void
    {
        $mainClient = new FakeGraphqlClient(self::TASKS);
        $crmClient = new FakeGraphqlClient(self::TASKS);
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = fn (FakeGraphqlClient $client, string $name) => new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 1, new DefaultDialect(), cache: new GraphqlCache($this->pool, namespace: $name));

        (new GraphqlEntityRepository($manager($mainClient, 'main'), FakeTask::class))->createQueryBuilder()->getQuery()->enableResultCache()->getResult();
        (new GraphqlEntityRepository($manager($crmClient, 'crm'), FakeTask::class))->createQueryBuilder()->getQuery()->enableResultCache()->getResult();

        self::assertSame([1, 1], [$mainClient->callCount, $crmClient->callCount]);
    }

    #[Test]
    public function itNeedsAPoolToCacheAResult(): void
    {
        $manager = new GraphqlManager(new GraphqlEntityMetadataFactory(), new FakeGraphqlClient(self::TASKS), new EntityHydrator(new GraphqlEntityMetadataFactory()), null, 1);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('result_cache');
        (new GraphqlEntityRepository($manager, FakeTask::class))->createQueryBuilder()->getQuery()->enableResultCache()->getResult();
    }

    /**
     * @return GraphqlEntityRepository<FakeTask>
     */
    private function repository(FakeGraphqlClient $client): GraphqlEntityRepository
    {
        return new GraphqlEntityRepository($this->manager($client, new DefaultDialect()), FakeTask::class);
    }

    private function manager(FakeGraphqlClient $client, GraphqlQueryDialect $dialect): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager(
            $metadataFactory,
            $client,
            new EntityHydrator($metadataFactory),
            $this->collector,
            1,
            $dialect,
            cache: new GraphqlCache($this->pool),
        );
    }

    /**
     * The expiry the pool holds for a result kept under a given key, as a Unix time.
     */
    private function expiry(string $key): float
    {
        $item = $this->pool->getItem(GraphqlCache::resultKey($key));
        self::assertTrue($item->isHit());
        self::assertInstanceOf(CacheItem::class, $item);
        $expiry = $item->getMetadata()[CacheItem::METADATA_EXPIRY] ?? null;
        self::assertIsNumeric($expiry);

        return (float) $expiry;
    }
}
