<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Command;

use GraphqlOrm\Cache\GraphqlCache;
use GraphqlOrm\Command\CachedEntities;
use GraphqlOrm\Command\CacheWarmupCommand;
use GraphqlOrm\Command\EntityClassLocator;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use GraphqlOrm\Registry\ManagerRouter;
use GraphqlOrm\Tests\Fixtures\Cache\CachedTask;
use GraphqlOrm\Tests\Fixtures\Cache\CachedUser;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class CacheWarmupCommandTest extends TestCase
{
    private GraphqlCache $cache;

    protected function setUp(): void
    {
        $this->cache = new GraphqlCache(
            entities: new ArrayAdapter(),
            regions: ['reference_data' => ['pool' => new ArrayAdapter()]],
        );
    }

    #[Test]
    public function itKeepsTheEntitiesOfAClassPageByPage(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['tasks' => ['items' => [self::task(1), self::task(2)], 'hasNextPage' => true, 'endCursor' => 'c2']]],
            ['data' => ['tasks' => ['items' => [self::task(3)], 'hasNextPage' => false, 'endCursor' => null]]],
        );
        $tester = $this->tester($client);

        $status = $tester->execute(['entity' => 'CachedTask', '--batch-size' => '2']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(2, $client->callCount);
        self::assertStringContainsString('first: 2', $client->queries[0]);
        self::assertStringContainsString('after: "c2"', $client->queries[1]);
        foreach ([1, 2, 3] as $id) {
            self::assertTrue($this->cache->containsEntity(CachedTask::class, $id));
        }

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('CachedTask default reference_data 3 3', $display);
        self::assertStringContainsString('3 entities kept in the second-level cache', $display);
    }

    #[Test]
    public function itWarmsEveryEntityKeptInTheCacheWithoutArgument(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['items' => [self::task(1)], 'hasNextPage' => false]]]);
        $tester = $this->tester($client, entityClasses: [CachedTask::class, FakeTask::class]);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(1, $client->callCount);
        self::assertTrue($this->cache->containsEntity(CachedTask::class, 1));
        self::assertStringNotContainsString('FakeTask', $tester->getDisplay());
    }

    #[Test]
    public function itLoadsTheEntitiesAtOnceWithADialectThatDoesNotPaginate(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => [self::task(1, envelope: false), self::task(2, envelope: false)]]]);
        $tester = $this->tester($client, dialect: new DefaultDialect());

        self::assertSame(Command::SUCCESS, $tester->execute(['entity' => 'CachedTask']));
        self::assertSame(1, $client->callCount);
        self::assertStringNotContainsString('first:', $client->queries[0]);
        self::assertTrue($this->cache->containsEntity(CachedTask::class, 2));
        self::assertStringContainsString('cannot paginate', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    #[Test]
    public function itFailsForAnEntityNotKeptInTheCache(): void
    {
        $client = new FakeGraphqlClient();
        $tester = $this->tester($client);

        self::assertSame(Command::FAILURE, $tester->execute(['entity' => FakeTask::class]));
        self::assertStringContainsString('is not kept in the second-level cache', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertSame(0, $client->callCount);
    }

    #[Test]
    public function itFailsWhenTheSecondLevelCacheIsDisabled(): void
    {
        $this->cache = new GraphqlCache();
        $tester = $this->tester(new FakeGraphqlClient());

        self::assertSame(Command::FAILURE, $tester->execute(['entity' => 'CachedTask']));
        self::assertStringContainsString('graphql_orm.second_level_cache', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    #[Test]
    public function itFailsForAnEntityOfAnotherManager(): void
    {
        $tester = $this->tester(new FakeGraphqlClient());

        self::assertSame(Command::FAILURE, $tester->execute(['entity' => 'CachedTask', '--manager' => 'crm']));
        self::assertStringContainsString('"crm" does not exist', $tester->getDisplay());
    }

    /**
     * @return array<string, mixed>
     */
    private static function task(int $id, bool $envelope = true): array
    {
        $tasks = $envelope ? ['items' => []] : [];

        return ['id' => $id, 'title' => 'Task ' . $id, 'dueDate' => null, 'assignee' => ['id' => 7, 'name' => 'User A', 'tasks' => $tasks]];
    }

    /**
     * @param list<class-string> $entityClasses
     */
    private function tester(FakeGraphqlClient $client, ?GraphqlQueryDialect $dialect = null, array $entityClasses = [CachedTask::class, CachedUser::class]): CommandTester
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $router = new ManagerRouter(['default' => ''], 'default');
        $manager = new GraphqlManager(
            $metadataFactory,
            $client,
            new EntityHydrator($metadataFactory),
            null,
            1,
            $dialect ?? new DataApiBuilderDialect(),
            cache: $this->cache,
            router: $router,
        );
        $managers = new GraphqlManagerRegistry(new ServiceLocator(['default' => static fn () => $manager]), $router);
        $command = new CacheWarmupCommand(
            $managers,
            new CachedEntities($managers, new EntityClassLocator(\dirname(__DIR__) . '/Fixtures/Cache', 'GraphqlOrm\\Tests\\Fixtures\\Cache'), ['default' => $entityClasses]),
        );

        return new CommandTester((new Command('graphqlorm:cache:warmup'))->setCode($command));
    }
}
