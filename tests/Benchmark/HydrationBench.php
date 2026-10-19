<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Benchmark;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Execution\IdentityMap;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Integration\Entity\Task;
use PhpBench\Attributes as Bench;

/**
 * Hydration of a large response: 1,000 tasks, each with its user and the user's tasks reduced to identifiers.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\ParamProviders('provideSetups')]
final class HydrationBench
{
    private const ROWS = 1000;

    /** @var GraphqlEntityRepository<Task> */
    private GraphqlEntityRepository $tasks;

    /**
     * @return iterable<string, array{readOnly: bool, identityMap: bool}>
     */
    public function provideSetups(): iterable
    {
        // The entities read are tracked for flush(), as by default.
        yield 'tracked' => ['readOnly' => false, 'identityMap' => false];
        yield 'read only' => ['readOnly' => true, 'identityMap' => false];
        // As configured by default in Symfony.
        yield 'tracked, identity map' => ['readOnly' => false, 'identityMap' => true];
    }

    /**
     * @param array{readOnly: bool, identityMap: bool} $setup
     */
    public function setUp(array $setup): void
    {
        $rows = [];

        for ($id = 1; $id <= self::ROWS; ++$id) {
            $user = $id % 10 + 1;
            $rows[] = [
                'id' => $id,
                'title' => 'Task ' . $id,
                'done' => $id % 2 === 0,
                'due_date' => '2026-01-15T10:00:00+00:00',
                'user' => ['id' => $user, 'name' => 'User ' . $user, 'tasks' => ['items' => [['id' => $id]]]],
            ];
        }

        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager(
            $metadataFactory,
            new StaticGraphqlClient(['data' => ['tasks' => ['items' => $rows]]]),
            new EntityHydrator($metadataFactory),
            null,
            2,
            new DataApiBuilderDialect(),
            identityMap: $setup['identityMap'] ? new IdentityMap() : null,
            readOnly: $setup['readOnly'],
        );
        $this->tasks = new GraphqlEntityRepository($manager, Task::class);
    }

    public function benchHydrateAThousandRows(): void
    {
        $this->tasks->findAll();
    }
}
