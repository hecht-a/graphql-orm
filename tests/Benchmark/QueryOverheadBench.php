<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Benchmark;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Execution\IdentityMap;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Integration\Entity\Task;
use PhpBench\Attributes as Bench;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

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
     * @return iterable<string, array{instrumented: bool, known: int}>
     */
    public function provideSetups(): iterable
    {
        yield 'bare' => ['instrumented' => false, 'known' => 0];
        // As in production with Symfony: logger and event dispatcher without listener, values masked; the collector is
        // debug only.
        yield 'instrumented' => ['instrumented' => true, 'known' => 0];
        // Late in a long request or a message handler, the identity map already holding many entities.
        yield 'many known entities' => ['instrumented' => false, 'known' => 5000];
    }

    /**
     * @param array{instrumented: bool, known: int} $setup
     */
    public function setUp(array $setup): void
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $identityMap = null;

        if ($setup['known'] > 0) {
            $identityMap = new IdentityMap();
            $known = [];

            for ($id = 1000; $id < 1000 + $setup['known']; ++$id) {
                $known[$id] = new Task();
            }

            $identityMap->merge([Task::class => $known], []);
        }

        $manager = new GraphqlManager(
            $metadataFactory,
            new StaticGraphqlClient(self::RESPONSE),
            new EntityHydrator($metadataFactory),
            null,
            2,
            new DataApiBuilderDialect(),
            logger: $setup['instrumented'] ? new NullLogger() : null,
            redactor: $setup['instrumented'] ? new QueryRedactor() : null,
            eventDispatcher: $setup['instrumented'] ? new EventDispatcher() : null,
            identityMap: $identityMap,
        );
        $this->tasks = new GraphqlEntityRepository($manager, Task::class);
    }

    public function benchFindBy(): void
    {
        $this->tasks->findBy(['done' => false], limit: 10);
    }
}
