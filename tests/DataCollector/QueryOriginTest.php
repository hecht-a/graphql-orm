<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\DataCollector;

use Doctrine\Common\Collections\ArrayCollection;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Dialect\HasuraDialect;
use GraphqlOrm\Exception\PartialEntityException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\Depth\ChainA;
use GraphqlOrm\Tests\Fixtures\Depth\ChainB;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeLabel;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeUserWithTaskCollection;
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
    public function itRecordsTheIdentifierOfTheEntityFound(): void
    {
        $this->repository(new FakeGraphqlClient(['data' => ['tasks' => []]]), FakeTask::class)->find(3);

        self::assertSame(['find', 'id'], [$this->origin(0)[0], $this->collector->getQueries()[0]['identifier_property'] ?? null]);
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
    public function itTellsTheProfilerTheEntitiesCreatedAndLoadedOnFirstAccess(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['chainAs' => [['id' => 1, 'label' => 'A', 'next' => ['id' => 2]]]]],
            ['data' => ['chainBs' => [['id' => 2, 'label' => 'B', 'next' => ['id' => 3]]]]],
        );
        $chainA = $this->repository($client, ChainA::class, lazyLoading: true)->findAll()[0];
        self::assertInstanceOf(ChainA::class, $chainA);

        self::assertSame('B', $chainA->next?->label);

        $loading = $this->collector->getLazyLoading();
        self::assertSame([ChainA::class . '::$next', 1, 1, [2]], [$loading[0]['relation'], $loading[0]['created'], $loading[0]['loaded'], $loading[0]['queries']]);
    }

    #[Test]
    public function itTellsTheProfilerThePartialEntitiesAndThePropertiesReadWithoutBeingSelected(): void
    {
        $client = new FakeGraphqlClient(['data' => ['labels' => [['code' => 'bug'], ['code' => 'docs']]]]);
        $labels = $this->repository($client, FakeLabel::class)->createQueryBuilder()->select('code')->getQuery()->getResult();
        self::assertIsArray($labels);
        self::assertInstanceOf(FakeLabel::class, $labels[0]);

        try {
            self::assertGreaterThan(0, $labels[0]->position);
        } catch (PartialEntityException) {
        }

        self::assertSame([[
            'class' => FakeLabel::class,
            'unselected' => ['position'],
            'hydrated' => 2,
            'queries' => [1],
            'exceptions' => 1,
            'missing' => ['position'],
        ]], $this->collector->getPartialEntities());
    }

    #[Test]
    public function itRecordsTheFieldIndexingTheResult(): void
    {
        $this->repository(new FakeGraphqlClient(['data' => ['labels' => [['code' => 'bug', 'position' => 1]]]]), FakeLabel::class)
            ->createQueryBuilder()
            ->indexBy('code')
            ->getQuery()
            ->getResult();

        self::assertSame('code', $this->collector->getQueries()[0]['index_by'] ?? null);
    }

    #[Test]
    public function itRecordsTheCollectionsHydratedByRelation(): void
    {
        $client = new FakeGraphqlClient(['data' => ['users' => [
            ['id' => 1, 'tasks' => [['id' => 7, 'title' => 'A'], ['id' => 8, 'title' => 'B']]],
            ['id' => 2, 'tasks' => []],
        ]]]);

        $this->repository($client, FakeUserWithTaskCollection::class)->findAll();

        self::assertSame(
            [['relation' => FakeUserWithTaskCollection::class . '::$tasks', 'type' => ArrayCollection::class, 'collections' => 2, 'items' => 2]],
            $this->collector->getQueries()[0]['collections'] ?? null,
        );
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

    #[Test]
    public function itMeasuresTheHydrationApartFromTheNetwork(): void
    {
        $tasks = array_map(fn (int $id) => ['id' => $id, 'title' => 'Task ' . $id], range(1, 50));

        $this->repository(new FakeGraphqlClient(['data' => ['tasks' => $tasks]]), FakeTask::class)->findAll();

        $hydration = $this->collector->getQueries()[0]['hydration_duration'] ?? null;
        self::assertIsFloat($hydration);
        self::assertGreaterThan(0.0, $hydration);
    }

    #[Test]
    public function itKeepsAPreviewOfTheResponseWithItsValuesMasked(): void
    {
        $this->repository(new FakeGraphqlClient(['data' => ['tasks' => [['id' => 1, 'title' => 'Secret']]]]), FakeTask::class)->findAll();

        $preview = $this->collector->getQueries()[0]['response_preview'] ?? null;
        self::assertIsString($preview);
        self::assertStringContainsString('"title": "***"', $preview);
        self::assertStringNotContainsString('Secret', $preview);
    }

    #[Test]
    public function itCutsTheResponsePreview(): void
    {
        $tasks = array_map(fn (int $id) => ['id' => $id, 'title' => str_repeat('a', 100)], range(1, 100));

        $this->repository(new FakeGraphqlClient(['data' => ['tasks' => $tasks]]), FakeTask::class)->findAll();

        $preview = $this->collector->getQueries()[0]['response_preview'] ?? null;
        self::assertIsString($preview);
        self::assertLessThanOrEqual(2048 + 4, \strlen($preview));
        self::assertStringEndsWith('…', $preview);
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
            redactor: new QueryRedactor(),
        );

        return new GraphqlEntityRepository($manager, $entityClass);
    }
}
