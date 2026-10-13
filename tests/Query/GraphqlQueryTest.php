<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\GraphqlQuery;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntity;
use GraphqlOrm\Tests\Fixtures\FakeEntityHydrator;
use GraphqlOrm\Tests\Fixtures\FakeMetadataFactory;
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
        $hydrator = new FakeEntityHydrator($stdClass);

        $manager = $this->createManager([
            'data' => [
                'tasks' => [
                    'id' => 1,
                ],
            ],
        ], $hydrator);

        $query = new GraphqlQuery('query', FakeEntity::class, $manager, new QueryOptions());

        self::assertSame([$stdClass], $query->getResult());
        self::assertSame(1, $hydrator->callCount);
    }

    public function testHydratesList(): void
    {
        $stdClass1 = new \stdClass();
        $stdClass2 = new \stdClass();
        $hydrator = new FakeEntityHydrator($stdClass1, $stdClass2);

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
        self::assertSame(2, $hydrator->callCount);
    }

    public function testGetOneOrNullResult(): void
    {
        $stdClass = new \stdClass();
        $hydrator = new FakeEntityHydrator($stdClass);

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

    /**
     * @param array<string, mixed> $response
     */
    private function createManager(array $response, ?EntityHydrator $hydrator = null): GraphqlManager
    {
        $metadataFactory = new FakeMetadataFactory(new GraphqlEntityMetadata(FakeEntity::class, 'tasks', FakeRepository::class, [], null));

        $hydrator ??= new FakeEntityHydrator();

        $manager = $this->createStub(GraphqlManager::class);

        $manager->method('getMetadataFactory')->willReturn($metadataFactory);
        $manager->method('getHydrator')->willReturn($hydrator);

        $manager
            ->method('execute')
            ->willReturnCallback(fn (QueryNode|string $_, callable $hydration) => $hydration($response, new GraphqlExecutionContext(), []));

        $manager
            ->method('send')
            ->willReturnCallback(fn (QueryNode|string $_, callable $hydration) => fn () => $hydration($response, new GraphqlExecutionContext(), []));

        $manager
            ->method('getDialect')
            ->willReturn(new DefaultDialect());

        return $manager;
    }
}
