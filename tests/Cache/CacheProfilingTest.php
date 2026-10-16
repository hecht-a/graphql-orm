<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Cache;

use GraphqlOrm\Cache\GraphqlCache;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\Cache\CachedTask;
use GraphqlOrm\Tests\Fixtures\Cache\CachedUser;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The profiler tells what the caches did for each query and each entity, and where they were evicted from.
 */
final class CacheProfilingTest extends TestCase
{
    private GraphqlOrmDataCollector $collector;

    private GraphqlCache $cache;

    protected function setUp(): void
    {
        $this->collector = new GraphqlOrmDataCollector();
        $this->cache = new GraphqlCache(new ArrayAdapter(), new ArrayAdapter(), 3600, ['reference_data' => ['pool' => new ArrayAdapter()]], $this->collector);
    }

    #[Test]
    public function itTellsTheKeyAndTheLifetimeOfAResult(): void
    {
        $this->repository(new FakeGraphqlClient(['data' => ['tasks' => []]]), FakeTask::class)
            ->createQueryBuilder()->getQuery()->enableResultCache(lifetime: 300, key: 'tasks')->getResult();

        $query = $this->collector->getQueries()[0];
        self::assertSame(['miss', 'tasks', 300], [$query['result_cache'] ?? null, $query['result_cache_key'] ?? null, $query['result_cache_lifetime'] ?? null]);
    }

    #[Test]
    public function itTellsWhichEntitiesTheSecondLevelCacheReadOrKeptAndWhy(): void
    {
        $this->repository(new FakeGraphqlClient(['data' => ['tasks' => [['id' => 1, 'title' => 'A', 'dueDate' => null, 'assignee' => ['id' => 7, 'name' => 'User A', 'tasks' => []]]]]]), CachedTask::class, maxDepth: 2)->findAll();
        $tasks = $this->repository(new FakeGraphqlClient(['data' => ['tasks' => []]]), CachedTask::class);

        $tasks->find(1);
        $tasks->find(2);

        self::assertSame([
            ['class' => CachedUser::class, 'region' => null, 'identifier' => '7', 'outcome' => 'kept', 'trigger' => 'query #1', 'count' => 1],
            ['class' => CachedTask::class, 'region' => 'reference_data', 'identifier' => '1', 'outcome' => 'kept', 'trigger' => 'query #1', 'count' => 1],
            ['class' => CachedTask::class, 'region' => 'reference_data', 'identifier' => '1', 'outcome' => 'hit', 'trigger' => 'find', 'count' => 1],
            ['class' => CachedTask::class, 'region' => 'reference_data', 'identifier' => '2', 'outcome' => 'miss', 'trigger' => 'find', 'count' => 1],
        ], $this->collector->getSecondLevelCacheEntries());
    }

    #[Test]
    public function itNamesTheRelationWhoseLazyLoadReadTheCache(): void
    {
        $this->repository(new FakeGraphqlClient(['data' => ['users' => [['id' => 7, 'name' => 'User A', 'tasks' => []]]]]), CachedUser::class)->findAll();
        $tasks = $this->repository(new FakeGraphqlClient(['data' => ['tasks' => [['id' => 2, 'title' => 'B', 'dueDate' => null, 'assignee' => ['id' => 7]]]]]), CachedTask::class, lazyLoading: true)->findAll();

        self::assertSame('User A', $tasks[0]->assignee?->name);

        $hits = array_values(array_filter($this->collector->getSecondLevelCacheEntries(), static fn (array $entry) => $entry['outcome'] === 'hit'));
        self::assertSame('lazy load of ' . CachedTask::class . '::$assignee', $hits[0]['trigger'] ?? null);
    }

    #[Test]
    public function itTellsTheEvictionsAndWhereTheyWereCalled(): void
    {
        $line = __LINE__ + 1;
        $this->cache->evictEntity(CachedTask::class, 1);
        $this->cache->evictEntityRegion(CachedUser::class);
        $this->cache->evictQueryResult('tasks');

        self::assertSame([
            ['call' => 'evictEntity', 'target' => CachedTask::class . ' #1', 'caller' => __FILE__ . ':' . $line],
            ['call' => 'evictEntityRegion', 'target' => CachedUser::class, 'caller' => __FILE__ . ':' . ($line + 1)],
            ['call' => 'evictQueryResult', 'target' => 'tasks', 'caller' => __FILE__ . ':' . ($line + 2)],
        ], $this->collector->getCacheEvictions());
    }

    /**
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
            $this->collector,
            $lazyLoading ? 0 : $maxDepth,
            new DefaultDialect(),
            lazyLoading: $lazyLoading,
            cache: $this->cache,
        );

        return new GraphqlEntityRepository($manager, $class);
    }
}
