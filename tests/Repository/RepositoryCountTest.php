<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Repository;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Dialect\HasuraDialect;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Schema\CountableFieldResolver;
use GraphqlOrm\Tests\Fixtures\FakeDataApiBuilderSchema;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeLabel;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class RepositoryCountTest extends TestCase
{
    #[Test]
    public function itCountsTheEntitiesMatchingTheCriteria(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks_aggregate' => ['aggregate' => ['count' => 3]]]]);
        $collector = new GraphqlOrmDataCollector();

        $count = $this->repository($client, new HasuraDialect(), $collector)->count(['title' => 'A']);

        self::assertSame(3, $count);
        self::assertStringContainsString('tasks_aggregate(where: { title: { _eq: "A" } }) {', $client->lastQuery);
        self::assertSame(1, $collector->getQueryCount());
    }

    #[Test]
    public function itCountsOnTheNumericIdentifierWithoutIntrospectingTheSchema(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['groupBy' => [['aggregations' => ['count' => 4]]]]]]);

        $count = $this->repository($client, $this->dataApiBuilder($client))->count();

        self::assertSame(4, $count);
        self::assertSame(1, $client->callCount);
        self::assertStringContainsString('count(field: id)', $client->lastQuery);
    }

    #[Test]
    public function itCountsOnAFieldNeverNullWhenTheIdentifierIsNoNumber(): void
    {
        $client = new FakeGraphqlClient(
            FakeDataApiBuilderSchema::introspectionResponse(),
            ['data' => ['labels' => ['groupBy' => [['aggregations' => ['count' => 5]]]]]],
        );

        $count = $this->repository($client, $this->dataApiBuilder($client), entityClass: FakeLabel::class)->count();

        self::assertSame(5, $count);
        self::assertStringContainsString('count(field: position)', $client->lastQuery);
    }

    #[Test]
    public function itRejectsADialectThatCannotCount(): void
    {
        $repository = $this->repository(new FakeGraphqlClient(), new DefaultDialect());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('CountingDialect');
        $repository->count();
    }

    /**
     * @param class-string $entityClass
     *
     * @return GraphqlEntityRepository<object>
     */
    private function repository(
        FakeGraphqlClient $client,
        GraphqlQueryDialect $dialect,
        ?GraphqlOrmDataCollector $collector = null,
        string $entityClass = FakeTask::class,
    ): GraphqlEntityRepository {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), $collector, 2, $dialect);

        return new GraphqlEntityRepository($manager, $entityClass);
    }

    private function dataApiBuilder(FakeGraphqlClient $client): DataApiBuilderDialect
    {
        return new DataApiBuilderDialect(new CountableFieldResolver($client, new ArrayAdapter(), 3600));
    }
}
