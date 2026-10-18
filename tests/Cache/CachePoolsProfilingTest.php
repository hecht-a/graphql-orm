<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Cache;

use GraphqlOrm\Cache\CachePoolDescriber;
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
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The profiler tells where the caches live, what they cost, the queries they add, and what is wrong with them.
 */
final class CachePoolsProfilingTest extends TestCase
{
    private GraphqlOrmDataCollector $collector;

    private GraphqlCache $cache;

    protected function setUp(): void
    {
        $this->collector = new GraphqlOrmDataCollector();
        $this->cache = new GraphqlCache(new ArrayAdapter(), new ArrayAdapter(), 3600, ['reference_data' => ['pool' => new ArrayAdapter()]], $this->collector);
    }

    #[Test]
    public function itDescribesThePoolsBehindTheCaches(): void
    {
        $describer = new CachePoolDescriber([
            'result cache' => ['id' => 'cache.app', 'pool' => new TraceableAdapter(new ArrayAdapter()), 'lifetime' => null],
            'reference_data' => ['id' => 'cache.graphql', 'pool' => new FilesystemAdapter('graphql_orm_test', 0, \dirname(__DIR__, 2) . '/cache'), 'lifetime' => 86400],
        ]);

        self::assertSame([
            ['name' => 'result cache', 'id' => 'cache.app', 'adapter' => 'Array', 'lifetime' => null],
            ['name' => 'reference_data', 'id' => 'cache.graphql', 'adapter' => 'Filesystem', 'lifetime' => 86400],
        ], $describer->describe());
    }

    #[Test]
    public function itMeasuresTheReadsAndTheWritesOfEachCache(): void
    {
        $this->repository(new FakeGraphqlClient(['data' => ['users' => [['id' => 7, 'name' => 'User A', 'tasks' => []]]]]), CachedUser::class)->findAll();
        $this->repository(new FakeGraphqlClient(), CachedUser::class)->find(7);

        $operations = $this->collector->getCacheOperations();
        self::assertSame(['default region'], array_column($operations, 'name'));
        self::assertSame([1, 1, 0], [$operations[0]['reads'], $operations[0]['writes'], $operations[0]['deletes']]);
        self::assertGreaterThan(0, $operations[0]['bytes_written']);
        self::assertGreaterThan(0.0, $operations[0]['duration']);
    }

    #[Test]
    public function itMarksTheQueriesSentByTheRelationsOfEntitiesReadFromTheCache(): void
    {
        $this->repository(new FakeGraphqlClient(['data' => ['users' => [['id' => 7, 'name' => 'User A', 'tasks' => [['id' => 1]]]]]]), CachedUser::class)->findAll();
        $user = $this->repository(new FakeGraphqlClient(['data' => ['tasks' => [['id' => 1, 'title' => 'A', 'dueDate' => null, 'assignee' => null]]]]), CachedUser::class)->find(7);
        self::assertInstanceOf(CachedUser::class, $user);

        self::assertSame('A', $user->tasks[0]->title);

        self::assertSame([false, true], array_column($this->collector->getQueries(), 'from_cached_entity'));
    }

    #[Test]
    public function itWarnsThatCacheIsIgnoredWhenTheSecondLevelCacheIsDisabled(): void
    {
        $collector = new GraphqlOrmDataCollector(
            configuration: ['second_level_cache' => ['enabled' => false]],
            metadataFactory: new GraphqlEntityMetadataFactory(),
            entityClasses: [CachedTask::class, FakeTask::class],
        );

        $collector->collect(new Request(), new Response());

        self::assertSame(
            ['#[GraphqlOrm\\Cache] is ignored on CachedTask: graphql_orm.second_level_cache.enabled is false.'],
            $collector->getCacheWarnings(),
        );
    }

    #[Test]
    public function itWarnsAboutAPoolKeepingItsValuesForTheRequestOnly(): void
    {
        $collector = new GraphqlOrmDataCollector(cachePools: new CachePoolDescriber([
            'result cache' => ['id' => 'cache.array', 'pool' => new ArrayAdapter(), 'lifetime' => null],
        ]));

        $collector->collect(new Request(), new Response());

        self::assertSame(
            ['The result cache is kept in cache.array, an ArrayAdapter: its values do not outlive the process.'],
            $collector->getCacheWarnings(),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return GraphqlEntityRepository<T>
     */
    private function repository(FakeGraphqlClient $client, string $class): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), $this->collector, 1, new DefaultDialect(), cache: $this->cache);

        return new GraphqlEntityRepository($manager, $class);
    }
}
