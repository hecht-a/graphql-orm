<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Logging;

use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\GraphqlQueryTrace;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class SlowQueryTest extends TestCase
{
    #[Test]
    public function itLogsAQueryOverTheThresholdAsAWarning(): void
    {
        $logger = new FakeLogger();

        $this->tasks(durationMs: 750.0, threshold: 500.0, logger: $logger)->findAll();

        self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        self::assertSame('Slow GraphQL query', $logger->records[0]['message']);
        self::assertSame(750.0, $logger->records[0]['context']['duration_ms']);
        self::assertSame(500.0, $logger->records[0]['context']['slow_query_threshold_ms']);
    }

    #[Test]
    public function itLogsAQueryUnderTheThresholdAsDebug(): void
    {
        $logger = new FakeLogger();

        $this->tasks(durationMs: 120.0, threshold: 500.0, logger: $logger)->findAll();

        self::assertSame(LogLevel::DEBUG, $logger->records[0]['level']);
    }

    #[Test]
    public function itReportsNoSlowQueryWithoutThreshold(): void
    {
        $logger = new FakeLogger();

        $this->tasks(durationMs: 60_000.0, threshold: null, logger: $logger)->findAll();

        self::assertSame(LogLevel::DEBUG, $logger->records[0]['level']);
    }

    #[Test]
    public function itCountsTheSlowQueriesInTheProfiler(): void
    {
        $collector = new GraphqlOrmDataCollector(slowQueryThreshold: 500.0);

        foreach ([120.0, 750.0, 500.0] as $duration) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = 'query { tasks { id } }';
            $trace->duration = $duration;
            $collector->addQuery($trace);
        }

        self::assertSame(2, $collector->getSlowQueryCount());
        self::assertSame([false, true, true], array_map(fn (array $query) => $query['slow'], $collector->getQueries()));
        self::assertSame(500.0, $collector->getSlowQueryThreshold());
    }

    #[Test]
    public function itCountsNoSlowQueryInTheProfilerWithoutThreshold(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $trace->duration = 60_000.0;

        $collector->addQuery($trace);

        self::assertSame(0, $collector->getSlowQueryCount());
    }

    /**
     * @return GraphqlEntityRepository<FakeTask>
     */
    private function tasks(float $durationMs, ?float $threshold, FakeLogger $logger): GraphqlEntityRepository
    {
        $client = new class($durationMs) implements GraphqlClientInterface {
            public function __construct(
                private readonly float $durationMs,
            ) {
            }

            public function query(string $query, GraphqlExecutionContext $context, array $variables = []): array
            {
                $context->trace->duration = $this->durationMs;

                return ['data' => ['tasks' => ['items' => []]]];
            }
        };
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager(
            $metadataFactory,
            $client,
            new EntityHydrator($metadataFactory),
            null,
            2,
            new DataApiBuilderDialect(),
            logger: $logger,
            slowQueryThreshold: $threshold,
        );

        return new GraphqlEntityRepository($manager, FakeTask::class);
    }
}
