<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Command;

use GraphqlOrm\Cache\GraphqlCache;
use GraphqlOrm\Command\CacheClearCommand;
use GraphqlOrm\Command\CachedEntities;
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

final class CacheClearCommandTest extends TestCase
{
    private ArrayAdapter $results;

    private ArrayAdapter $entities;

    private ArrayAdapter $referenceData;

    protected function setUp(): void
    {
        $this->results = new ArrayAdapter();
        $this->entities = new ArrayAdapter();
        $this->referenceData = new ArrayAdapter();

        $cache = $this->cache();
        $cache->putEntityRow(CachedTask::class, 1, ['id' => 1, 'title' => 'Task A', 'dueDate' => null, 'assignee' => null]);
        $cache->putEntityRow(CachedUser::class, 7, ['id' => 7, 'name' => 'User A', 'tasks' => []]);
        $cache->saveResult($cache->resultItem('', [], 'tasks', null), ['data' => ['tasks' => []]], null);
        $cache->saveResult($cache->resultItem('', [], 'users', null), ['data' => ['users' => []]], null);
    }

    #[Test]
    public function itClearsTheResultsAndTheEntitiesByDefault(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame([false, false, false, false], $this->kept());
        self::assertStringContainsString('Caches of "default" cleared', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    #[Test]
    public function itClearsTheResultsOnly(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--results' => true]));
        self::assertSame([false, false, true, true], $this->kept());
    }

    #[Test]
    public function itClearsTheEntitiesOnly(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--entities' => true]));
        self::assertSame([true, true, false, false], $this->kept());
    }

    #[Test]
    public function itClearsTheEntitiesOfAClass(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--class' => 'CachedTask']));
        self::assertSame([true, true, false, true], $this->kept());
    }

    #[Test]
    public function itClearsAResultByItsKey(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--key' => 'tasks']));
        self::assertSame([false, true, true, true], $this->kept());
    }

    #[Test]
    public function itFailsForAClassNotKeptInTheCache(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--class' => FakeTask::class]));
        self::assertStringContainsString('is not kept in the second-level cache', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertSame([true, true, true, true], $this->kept());
    }

    /**
     * Whether the result "tasks", the result "users", the task and the user are kept, as another process reads them.
     *
     * @return list<bool>
     */
    private function kept(): array
    {
        $cache = $this->cache();

        return [
            $cache->resultItem('', [], 'tasks', null)->isHit(),
            $cache->resultItem('', [], 'users', null)->isHit(),
            $cache->containsEntity(CachedTask::class, 1),
            $cache->containsEntity(CachedUser::class, 7),
        ];
    }

    private function cache(): GraphqlCache
    {
        return new GraphqlCache(
            results: $this->results,
            entities: $this->entities,
            regions: ['reference_data' => ['pool' => $this->referenceData]],
        );
    }

    private function tester(): CommandTester
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $router = new ManagerRouter(['default' => ''], 'default');
        $manager = new GraphqlManager($metadataFactory, new FakeGraphqlClient(), new EntityHydrator($metadataFactory), null, 1, cache: $this->cache(), router: $router);
        $managers = new GraphqlManagerRegistry(new ServiceLocator(['default' => static fn () => $manager]), $router);
        $entities = new CachedEntities(
            $managers,
            new EntityClassLocator(\dirname(__DIR__) . '/Fixtures/Cache', 'GraphqlOrm\\Tests\\Fixtures\\Cache'),
            ['default' => [CachedTask::class, CachedUser::class, FakeTask::class]],
        );

        return new CommandTester((new Command('graphqlorm:cache:clear'))->setCode(new CacheClearCommand($managers, $entities)));
    }
}
