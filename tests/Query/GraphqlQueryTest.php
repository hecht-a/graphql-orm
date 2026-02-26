<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\GraphqlQuery;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntity;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;
use PHPUnit\Framework\TestCase;

final class GraphqlQueryTest extends TestCase
{
    public function testGetGraphQL(): void
    {
        $query = new GraphqlQuery('query { tasks { id } }', FakeEntity::class, $this->createManager([]), new QueryOptions());

        self::assertSame('query { tasks { id } }', $query->getGraphQL());
    }

    public function testReturnsEmptyWhenNoData(): void
    {
        $manager = $this->createManager([
            'data' => [],
        ]);

        $query = new GraphqlQuery('query', FakeEntity::class, $manager, new QueryOptions());

        self::assertSame([], $query->getResult());
    }

    public function testThrowsWhenInvalidResponse(): void
    {
        $this->expectException(\RuntimeException::class);

        $manager = $this->createManager([
            'data' => [
                'tasks' => 'invalid',
            ],
        ]);

        (new GraphqlQuery('query', FakeEntity::class, $manager, new QueryOptions()))->getResult();
    }

    public function testHydratesSingleObject(): void
    {
        $stdClass = new \stdClass();
        $hydrator = $this->createMock(EntityHydrator::class);

        $hydrator
            ->expects(self::once())
            ->method('hydrate')
            ->willReturn($stdClass);

        $manager = $this->createManager([
            'data' => [
                'tasks' => [
                    'id' => 1,
                ],
            ],
        ], $hydrator);

        $query = new GraphqlQuery('query', FakeEntity::class, $manager, new QueryOptions());

        self::assertSame([$stdClass], $query->getResult());
    }

    public function testHydratesList(): void
    {
        $stdClass1 = new \stdClass();
        $stdClass2 = new \stdClass();
        $hydrator = $this->createMock(EntityHydrator::class);

        $hydrator
            ->expects(self::exactly(2))
            ->method('hydrate')
            ->willReturnOnConsecutiveCalls($stdClass1, $stdClass2);

        $manager = $this->createManager([
            'data' => [
                'tasks' => [
                    ['id' => 1],
                    ['id' => 2],
                ],
            ],
        ], $hydrator);

        $query = new GraphqlQuery('query', FakeEntity::class, $manager, new QueryOptions());

        self::assertSame([$stdClass1, $stdClass2], $query->getResult());
    }

    public function testGetOneOrNullResult(): void
    {
        $stdClass = new \stdClass();
        $hydrator = $this->createStub(EntityHydrator::class);

        $hydrator
            ->method('hydrate')
            ->willReturn($stdClass);

        $manager = $this->createManager([
            'data' => [
                'tasks' => [['id' => 1]],
            ],
        ], $hydrator);

        $query = new GraphqlQuery('query', FakeEntity::class, $manager, new QueryOptions());

        self::assertSame($stdClass, $query->getOneOrNullResult());
    }

    public function testGetOneOrNullResultReturnsNull(): void
    {
        $manager = $this->createManager([
            'data' => [],
        ]);

        $query = new GraphqlQuery('query', FakeEntity::class, $manager, new QueryOptions());

        self::assertNull($query->getOneOrNullResult());
    }

    private function createManager(array $response, ?EntityHydrator $hydrator = null): GraphqlManager
    {
        $metadataFactory = $this->createStub(GraphqlEntityMetadataFactory::class);

        $metadataFactory
            ->method('getMetadata')
            ->willReturn(new GraphqlEntityMetadata(FakeEntity::class, 'tasks', FakeRepository::class, [], null));

        $hydrator ??= $this->createStub(EntityHydrator::class);

        $manager = $this->createStub(GraphqlManager::class);

        $manager->metadataFactory = $metadataFactory;
        $manager->hydrator = $hydrator;

        $manager
            ->method('execute')
            ->willReturnCallback(fn ($_, $hydration) => $hydration($response, new GraphqlExecutionContext(), []));

        $manager
            ->method('getDialect')
            ->willReturn(new DefaultDialect());

        return $manager;
    }
}
