<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\GraphqlQuery;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\TestCase;

final class GraphqlQueryTest extends TestCase
{
    public function testGetGraphQL(): void
    {
        $query = new GraphqlQuery('query { tasks { id } }', FakeTask::class, $this->createManager([]), new QueryOptions());

        self::assertSame('query { tasks { id } }', $query->getGraphQL());
    }

    public function testReturnsEmptyWhenNoData(): void
    {
        $manager = $this->createManager([
            'data' => [],
        ]);

        $query = new GraphqlQuery('query', FakeTask::class, $manager, new QueryOptions());

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

        (new GraphqlQuery('query', FakeTask::class, $manager, new QueryOptions()))->getResult();
    }

    public function testHydratesSingleObject(): void
    {
        $manager = $this->createManager([
            'data' => [
                'tasks' => [
                    'id' => 1,
                ],
            ],
        ]);

        $query = new GraphqlQuery('query', FakeTask::class, $manager, new QueryOptions());

        self::assertSame([1], self::ids($query->getResult()));
    }

    public function testHydratesList(): void
    {
        $manager = $this->createManager([
            'data' => [
                'tasks' => [
                    ['id' => 1],
                    ['id' => 2],
                ],
            ],
        ]);

        $query = new GraphqlQuery('query', FakeTask::class, $manager, new QueryOptions());

        self::assertSame([1, 2], self::ids($query->getResult()));
    }

    public function testGetOneOrNullResult(): void
    {
        $manager = $this->createManager([
            'data' => [
                'tasks' => [['id' => 1]],
            ],
        ]);

        $query = new GraphqlQuery('query', FakeTask::class, $manager, new QueryOptions());

        $task = $query->getOneOrNullResult();
        self::assertInstanceOf(FakeTask::class, $task);
        self::assertSame(1, $task->id);
    }

    public function testGetOneOrNullResultReturnsNull(): void
    {
        $manager = $this->createManager([
            'data' => [],
        ]);

        $query = new GraphqlQuery('query', FakeTask::class, $manager, new QueryOptions());

        self::assertNull($query->getOneOrNullResult());
    }

    /**
     * @param array<string, mixed> $response
     */
    private function createManager(array $response): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager($metadataFactory, new FakeGraphqlClient($response), new EntityHydrator($metadataFactory), null, 2, new DefaultDialect());
    }

    /**
     * @param mixed $tasks the result of a query
     *
     * @return list<int>
     */
    private static function ids(mixed $tasks): array
    {
        self::assertIsArray($tasks);
        self::assertContainsOnlyInstancesOf(FakeTask::class, $tasks);

        return array_values(array_map(fn (FakeTask $task) => $task->id, $tasks));
    }
}
