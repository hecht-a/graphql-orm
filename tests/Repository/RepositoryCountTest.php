<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Repository;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Dialect\HasuraDialect;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
    public function itRejectsADialectThatCannotCount(): void
    {
        $repository = $this->repository(new FakeGraphqlClient(), new DefaultDialect());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('CountingDialect');
        $repository->count();
    }

    /**
     * @return GraphqlEntityRepository<FakeTask>
     */
    private function repository(FakeGraphqlClient $client, GraphqlQueryDialect $dialect, ?GraphqlOrmDataCollector $collector = null): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), $collector, 2, $dialect);

        return new GraphqlEntityRepository($manager, FakeTask::class);
    }
}
