<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Benchmark;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Integration\Entity\Task;
use PhpBench\Attributes as Bench;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;

/**
 * What the bundle costs around one small query, the network left aside: building, compiling, tracing, hydrating.
 * The manager lives across the queries, as in a request.
 */
#[Bench\Revs(1000)]
#[Bench\BeforeMethods('setUp')]
#[Bench\ParamProviders('provideSetups')]
final class QueryOverheadBench
{
    private const RESPONSE = ['data' => ['tasks' => ['items' => [
        ['id' => 1, 'title' => 'A', 'done' => false, 'due_date' => null, 'user' => null],
    ]]]];

    /** @var GraphqlEntityRepository<Task> */
    private GraphqlEntityRepository $tasks;

    /**
     * @return iterable<string, array{instrumented: bool}>
     */
    public function provideSetups(): iterable
    {
        yield 'bare' => ['instrumented' => false];
        // As in production with Symfony: logger and event dispatcher, values masked; the collector is debug only.
        yield 'instrumented' => ['instrumented' => true];
    }

    /**
     * @param array{instrumented: bool} $setup
     */
    public function setUp(array $setup): void
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager(
            $metadataFactory,
            new StaticGraphqlClient(self::RESPONSE),
            new EntityHydrator($metadataFactory),
            null,
            2,
            new DataApiBuilderDialect(),
            logger: $setup['instrumented'] ? new NullLogger() : null,
            redactor: $setup['instrumented'] ? new QueryRedactor() : null,
            eventDispatcher: $setup['instrumented'] ? new class implements EventDispatcherInterface {
                public function dispatch(object $event): object
                {
                    return $event;
                }
            } : null,
        );
        $this->tasks = new GraphqlEntityRepository($manager, Task::class);
    }

    public function benchFindBy(): void
    {
        $this->tasks->findBy(['done' => false], limit: 10);
    }
}
