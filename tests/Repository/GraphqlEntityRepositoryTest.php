<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Repository;

use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\GraphqlQueryBuilder;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\TestCase;

final class GraphqlEntityRepositoryTest extends TestCase
{
    public function testCreateQueryBuilder(): void
    {
        $manager = $this->createManager([]);

        $repo = new GraphqlEntityRepository($manager, FakeTask::class);

        self::assertInstanceOf(GraphqlQueryBuilder::class, $repo->createQueryBuilder());
    }

    public function testFindAllCallsFindBy(): void
    {
        $manager = $this->createManager([
            'data' => [
                'tasks' => [['id' => 1]],
            ],
        ]);

        $repo = new GraphqlEntityRepository($manager, FakeTask::class);

        self::assertSame([1], self::ids($repo->findAll()));
    }

    public function testFindByHydratesList(): void
    {
        $manager = $this->createManager([
            'data' => [
                'tasks' => [
                    ['id' => 1],
                    ['id' => 2],
                ],
            ],
        ]);

        $repo = new GraphqlEntityRepository($manager, FakeTask::class);

        self::assertSame([1, 2], self::ids($repo->findBy(['id' => 1])));
    }

    public function testFindByHydratesSingleObject(): void
    {
        $manager = $this->createManager([
            'data' => [
                'tasks' => [
                    'id' => 1,
                ],
            ],
        ]);

        $repo = new GraphqlEntityRepository($manager, FakeTask::class);

        self::assertSame([1], self::ids($repo->findBy([])));
    }

    public function testFindByReturnsEmptyWhenNoData(): void
    {
        $manager = $this->createManager([
            'data' => [],
        ]);

        $repo = new GraphqlEntityRepository($manager, FakeTask::class);

        self::assertSame([], $repo->findBy([]));
    }

    public function testFindByThrowsOnInvalidResponse(): void
    {
        $this->expectException(InvalidGraphqlResponseException::class);

        $manager = $this->createManager([
            'data' => [
                'tasks' => 'invalid',
            ],
        ]);

        (new GraphqlEntityRepository($manager, FakeTask::class))->findBy([]);
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
     * @param array<FakeTask> $tasks
     *
     * @return list<int>
     */
    private static function ids(array $tasks): array
    {
        return array_values(array_map(fn (FakeTask $task) => $task->id, $tasks));
    }
}
