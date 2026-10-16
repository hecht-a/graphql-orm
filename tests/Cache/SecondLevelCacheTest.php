<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Cache;

use GraphqlOrm\Cache\GraphqlCache;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\Cache\CachedInUnknownRegion;
use GraphqlOrm\Tests\Fixtures\Cache\CachedTask;
use GraphqlOrm\Tests\Fixtures\Cache\CachedUser;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;

/**
 * The entities marked #[Cache] are kept between requests once hydrated in full, and find(), the references and the lazy
 * loads read them from there, as the second-level cache of Doctrine. The relations are kept by their identifier.
 */
final class SecondLevelCacheTest extends TestCase
{
    private const TASK = ['id' => 1, 'title' => 'Write the docs', 'dueDate' => '2026-11-02T09:00:00+00:00', 'assignee' => ['id' => 7, 'name' => 'User A']];

    private ArrayAdapter $pool;

    private ArrayAdapter $referenceData;

    private GraphqlCache $cache;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->referenceData = new ArrayAdapter();
        $this->cache = $this->cache();
    }

    #[Test]
    public function itFindsAnEntityKeptByAnEarlierRequest(): void
    {
        $this->keepTask();
        $client = new FakeGraphqlClient();

        $task = $this->repository($client, CachedTask::class)->find(1);

        self::assertInstanceOf(CachedTask::class, $task);
        self::assertSame(['Write the docs', '2026-11-02'], [$task->title, $task->dueDate?->format('Y-m-d')]);
        self::assertSame(0, $client->callCount);
        self::assertTrue($this->cache->containsEntity(CachedTask::class, 1));
    }

    #[Test]
    public function itKeepsARelationByItsIdentifierAndLoadsItOnFirstAccess(): void
    {
        $this->keepTask();
        $client = new FakeGraphqlClient(['data' => ['users' => [['id' => 7, 'name' => 'User A', 'tasks' => []]]]]);

        $task = $this->repository($client, CachedTask::class)->find(1);
        self::assertInstanceOf(CachedTask::class, $task);
        self::assertSame(0, $client->callCount);

        self::assertSame('User A', $task->assignee?->name);
        self::assertSame(1, $client->callCount);
    }

    #[Test]
    public function itServesTheLazyLoadsFromTheCache(): void
    {
        $this->repository(new FakeGraphqlClient(['data' => ['users' => [['id' => 7, 'name' => 'User A', 'tasks' => [['id' => 1]]]]]]), CachedUser::class)->findAll();
        $client = new FakeGraphqlClient(['data' => ['tasks' => [['id' => 2, 'title' => 'Review', 'dueDate' => null, 'assignee' => ['id' => 7]]]]]);

        $tasks = $this->repository($client, CachedTask::class, lazyLoading: true)->findAll();

        self::assertSame('User A', $tasks[0]->assignee?->name);
        self::assertSame(1, $client->callCount);
    }

    #[Test]
    public function itKeepsACollectionByTheIdentifiersOfItsEntities(): void
    {
        $this->repository(new FakeGraphqlClient(['data' => ['users' => [['id' => 7, 'name' => 'User A', 'tasks' => [['id' => 1], ['id' => 2]]]]]]), CachedUser::class)->findAll();
        $client = new FakeGraphqlClient();

        $user = $this->repository($client, CachedUser::class)->find(7);

        self::assertInstanceOf(CachedUser::class, $user);
        self::assertCount(2, $user->tasks);
        self::assertSame(2, $user->tasks[1]->id);
        self::assertSame(0, $client->callCount);
    }

    #[Test]
    public function itKeepsOnlyTheEntitiesHydratedInFull(): void
    {
        $this->repository(new FakeGraphqlClient(['data' => ['tasks' => [['id' => 1, 'title' => 'Write the docs']]]]), CachedTask::class)
            ->createQueryBuilder()->select('title')->getQuery()->getResult();

        self::assertFalse($this->cache->containsEntity(CachedTask::class, 1));
    }

    #[Test]
    public function itKeepsOnlyTheEntitiesMarkedCache(): void
    {
        $this->repository(new FakeGraphqlClient(['data' => ['tasks' => [['id' => 1, 'title' => 'A']]]]), FakeTask::class)->findAll();

        self::assertFalse($this->cache->containsEntity(FakeTask::class, 1));
    }

    #[Test]
    public function itUsesThePoolOfTheRegionAndTheLifetimeOfTheEntity(): void
    {
        $before = microtime(true);
        $this->keepTask();

        $expiry = $this->expiry($this->referenceData);
        self::assertGreaterThanOrEqual($before + 600, $expiry);
        self::assertLessThanOrEqual(microtime(true) + 600, $expiry);
    }

    #[Test]
    public function itUsesTheDefaultPoolAndLifetimeWithoutRegion(): void
    {
        $before = microtime(true);
        $this->repository(new FakeGraphqlClient(['data' => ['users' => [['id' => 7, 'name' => 'User A', 'tasks' => []]]]]), CachedUser::class)->findAll();

        self::assertTrue($this->cache->containsEntity(CachedUser::class, 7));
        self::assertGreaterThanOrEqual($before + 3600, $this->expiry($this->pool));
    }

    #[Test]
    public function itDoesNotProlongAnEntityReadFromTheCache(): void
    {
        $this->keepTask();
        $expiry = $this->expiry($this->referenceData);

        usleep(1000);
        $this->repository(new FakeGraphqlClient(), CachedTask::class)->find(1);

        self::assertSame($expiry, $this->expiry($this->referenceData));
    }

    #[Test]
    public function itEvictsAnEntity(): void
    {
        $this->keepTask();

        $this->cache->evictEntity(CachedTask::class, 1);

        self::assertFalse($this->cache->containsEntity(CachedTask::class, 1));
    }

    #[Test]
    public function itEvictsEveryEntityOfAClass(): void
    {
        $this->keepTask();

        $this->cache->evictEntityRegion(CachedTask::class);

        self::assertFalse($this->cache->containsEntity(CachedTask::class, 1));
    }

    #[Test]
    public function itSeesTheEvictionOfAnotherProcessAfterAReset(): void
    {
        $this->keepTask();
        self::assertTrue($this->cache->containsEntity(CachedTask::class, 1));

        $this->cache()->evictEntityRegion(CachedTask::class);
        $this->cache->reset();

        self::assertFalse($this->cache->containsEntity(CachedTask::class, 1));
    }

    #[Test]
    public function itKeepsNothingWhenTheSecondLevelCacheIsDisabled(): void
    {
        $this->cache = new GraphqlCache();
        $this->keepTask();

        self::assertFalse($this->cache->containsEntity(CachedTask::class, 1));
    }

    #[Test]
    public function itNeedsTheRegionOfAnEntityToBeConfigured(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"unknown"');
        $this->repository(new FakeGraphqlClient(['data' => ['labels' => [['code' => 'bug']]]]), CachedInUnknownRegion::class)->findAll();
    }

    #[Test]
    public function itKeepsAnEntityOnceForAResponseHoldingItSeveralTimes(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $this->cache = new GraphqlCache(entities: $this->pool, defaultLifetime: 3600, regions: ['reference_data' => ['pool' => $this->referenceData]], collector: $collector);
        $user = ['id' => 7, 'name' => 'User A', 'tasks' => []];

        $this->repository(new FakeGraphqlClient(['data' => ['tasks' => [
            ['id' => 1, 'title' => 'A', 'dueDate' => null, 'assignee' => $user],
            ['id' => 2, 'title' => 'B', 'dueDate' => null, 'assignee' => $user],
        ]]]), CachedTask::class, maxDepth: 2)->findAll();

        self::assertSame([[CachedUser::class, 1], [CachedTask::class, 2]], array_map(
            static fn (array $entry) => [$entry['class'], $entry['puts']],
            $collector->getSecondLevelCache(),
        ));
    }

    #[Test]
    public function itTellsTheProfilerTheHitsTheMissesAndTheEntitiesKept(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $this->cache = new GraphqlCache(entities: $this->pool, defaultLifetime: 3600, regions: ['reference_data' => ['pool' => $this->referenceData]], collector: $collector);
        $this->repository(new FakeGraphqlClient(['data' => ['tasks' => []]]), CachedTask::class)->find(1);
        $this->keepTask();

        $this->repository(new FakeGraphqlClient(), CachedTask::class)->find(1);

        self::assertSame([['class' => CachedTask::class, 'region' => 'reference_data', 'hits' => 1, 'misses' => 1, 'puts' => 1]], $collector->getSecondLevelCache());
    }

    private function keepTask(): void
    {
        $this->repository(new FakeGraphqlClient(['data' => ['tasks' => [self::TASK]]]), CachedTask::class)->findAll();
    }

    private function cache(): GraphqlCache
    {
        return new GraphqlCache(
            entities: $this->pool,
            defaultLifetime: 3600,
            regions: ['reference_data' => ['pool' => $this->referenceData, 'lifetime' => 86400]],
        );
    }

    /**
     * A repository of a manager of its own, as in another request: only the second-level cache is shared.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return GraphqlEntityRepository<T>
     */
    private function repository(FakeGraphqlClient $client, string $class, bool $lazyLoading = false, int $maxDepth = 1): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager(
            $metadataFactory,
            $client,
            new EntityHydrator($metadataFactory),
            null,
            $lazyLoading ? 0 : $maxDepth,
            new DefaultDialect(),
            lazyLoading: $lazyLoading,
            cache: $this->cache,
        );

        return new GraphqlEntityRepository($manager, $class);
    }

    /**
     * The expiry of the only entity of a pool, as a Unix time.
     */
    private function expiry(ArrayAdapter $pool): float
    {
        $keys = array_values(array_filter(array_keys($pool->getValues()), static fn (string $key) => str_starts_with($key, 'graphql_orm.entity.')));
        self::assertCount(1, $keys);
        $item = $pool->getItem($keys[0]);
        self::assertInstanceOf(CacheItem::class, $item);
        $expiry = $item->getMetadata()[CacheItem::METADATA_EXPIRY] ?? null;
        self::assertIsNumeric($expiry);

        return (float) $expiry;
    }
}
