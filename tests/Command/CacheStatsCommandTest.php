<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Command;

use GraphqlOrm\Cache\CachePoolDescriber;
use GraphqlOrm\Cache\GraphqlCache;
use GraphqlOrm\Command\CachedEntities;
use GraphqlOrm\Command\CacheStatsCommand;
use GraphqlOrm\Command\EntityClassLocator;
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

final class CacheStatsCommandTest extends TestCase
{
    #[Test]
    public function itDescribesThePoolsAndTheEntitiesOfEachCache(): void
    {
        $results = new ArrayAdapter();
        $entities = new ArrayAdapter();
        $referenceData = new ArrayAdapter();
        $cache = new GraphqlCache(
            results: $results,
            entities: $entities,
            defaultLifetime: 3600,
            regions: ['reference_data' => ['pool' => $referenceData, 'lifetime' => 86400]],
        );
        $cache->evictQueryResults();
        $cache->evictQueryResults();
        $cache->evictEntityRegion(CachedTask::class);
        $pools = new CachePoolDescriber([
            GraphqlCache::RESULT_CACHE => ['id' => 'cache.graphql', 'pool' => $results, 'lifetime' => null],
            GraphqlCache::DEFAULT_REGION => ['id' => 'cache.app', 'pool' => $entities, 'lifetime' => 3600],
            'reference_data' => ['id' => 'cache.reference_data', 'pool' => $referenceData, 'lifetime' => 86400],
        ]);
        $tester = $this->tester($cache, $pools);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Manager "default"', $display);
        self::assertStringContainsString('result cache cache.graphql Array pool default 2', $display);
        self::assertStringContainsString('default region cache.app Array 3600 s', $display);
        self::assertStringContainsString('reference_data cache.reference_data Array 86400 s', $display);
        self::assertStringContainsString('CachedTask reference_data 600 s 1', $display);
        self::assertStringContainsString('CachedUser default region 3600 s 0', $display);
        self::assertStringNotContainsString('FakeTask', $display);
    }

    #[Test]
    public function itTellsTheCachesThatAreDisabled(): void
    {
        $tester = $this->tester(new GraphqlCache(), new CachePoolDescriber([]));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('No result cache: set graphql_orm.result_cache.pool', $display);
        self::assertStringContainsString('The second-level cache is disabled', $display);
    }

    #[Test]
    public function itFailsForAnUnknownManager(): void
    {
        $tester = $this->tester(new GraphqlCache(), new CachePoolDescriber([]));

        self::assertSame(Command::FAILURE, $tester->execute(['--manager' => 'crm']));
        self::assertStringContainsString('"crm" does not exist', $tester->getDisplay());
    }

    private function tester(GraphqlCache $cache, CachePoolDescriber $pools): CommandTester
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $router = new ManagerRouter(['default' => ''], 'default');
        $manager = new GraphqlManager($metadataFactory, new FakeGraphqlClient(), new EntityHydrator($metadataFactory), null, 1, cache: $cache, router: $router);
        $managers = new GraphqlManagerRegistry(new ServiceLocator(['default' => static fn () => $manager]), $router);
        $entities = new CachedEntities(
            $managers,
            new EntityClassLocator(\dirname(__DIR__) . '/Fixtures/Cache', 'GraphqlOrm\\Tests\\Fixtures\\Cache'),
            ['default' => [CachedTask::class, CachedUser::class, FakeTask::class]],
        );
        $command = new CacheStatsCommand($managers, $entities, new ServiceLocator(['default' => static fn () => $pools]));

        return new CommandTester((new Command('graphqlorm:cache:stats'))->setCode($command));
    }
}
