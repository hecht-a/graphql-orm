<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\DataCollector;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Dialect\HasuraDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\Depth\ChainA;
use GraphqlOrm\Tests\Fixtures\Depth\ChainB;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The profiler tells which method of the bundle sent each query, for which entity, and when.
 */
final class QueryOriginTest extends TestCase
{
    private GraphqlOrmDataCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new GraphqlOrmDataCollector();
    }

    #[Test]
    public function itRecordsTheRepositoryMethodSendingTheQuery(): void
    {
        $this->repository(new FakeGraphqlClient(['data' => ['tasks' => []]]), FakeTask::class)->findBy(['title' => 'A']);

        self::assertSame(['findBy', FakeTask::class], $this->origin(0));
    }

    #[Test]
    public function itRecordsTheQueryMethodCalledOnAQueryBuilder(): void
    {
        $this->repository(new FakeGraphqlClient(['data' => ['tasks' => []]]), FakeTask::class)
            ->createQueryBuilder()
            ->getQuery()
            ->getOneOrNullResult();

        self::assertSame(['getOneOrNullResult', FakeTask::class], $this->origin(0));
    }

    #[Test]
    public function itRecordsACount(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks_aggregate' => ['aggregate' => ['count' => 2]]]]);

        $this->repository($client, FakeTask::class, new HasuraDialect())->count();

        self::assertSame(['count', FakeTask::class], $this->origin(0));
    }

    #[Test]
    public function itRecordsALazyLoad(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['chainAs' => [['id' => 1, 'label' => 'A', 'next' => ['id' => 2]]]]],
            ['data' => ['chainBs' => [['id' => 2, 'label' => 'B', 'next' => ['id' => 3]]]]],
        );
        $chainA = $this->repository($client, ChainA::class, lazyLoading: true)->findAll()[0];
        self::assertInstanceOf(ChainA::class, $chainA);

        self::assertSame('B', $chainA->next?->label);

        self::assertSame(['findAll', ChainA::class], $this->origin(0));
        self::assertSame(['lazy', ChainB::class], $this->origin(1));
        self::assertSame(ChainA::class . '::$next', $this->collector->getQueries()[1]['lazy_relation'] ?? null);
    }

    #[Test]
    public function itRecordsWhenTheQueryStarted(): void
    {
        $before = microtime(true);

        $this->repository(new FakeGraphqlClient(['data' => ['tasks' => []]]), FakeTask::class)->findAll();

        $startedAt = $this->collector->getQueries()[0]['started_at'] ?? null;
        self::assertIsFloat($startedAt);
        self::assertGreaterThanOrEqual($before, $startedAt);
        self::assertLessThanOrEqual(microtime(true), $startedAt);
    }

    /**
     * @return array{mixed, mixed} the origin and the entity class of a recorded query
     */
    private function origin(int $index): array
    {
        $query = $this->collector->getQueries()[$index];

        return [$query['origin'] ?? null, $query['entity_class'] ?? null];
    }

    /**
     * @param class-string $entityClass
     *
     * @return GraphqlEntityRepository<object>
     */
    private function repository(FakeGraphqlClient $client, string $entityClass, ?GraphqlQueryDialect $dialect = null, bool $lazyLoading = false): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager(
            $metadataFactory,
            $client,
            new EntityHydrator($metadataFactory),
            $this->collector,
            $lazyLoading ? 0 : 2,
            dialect: $dialect ?? new DefaultDialect(),
            lazyLoading: $lazyLoading,
        );

        return new GraphqlEntityRepository($manager, $entityClass);
    }
}
