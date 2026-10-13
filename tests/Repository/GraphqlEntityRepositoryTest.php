<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Repository;

use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\GraphqlQueryBuilder;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntity;
use GraphqlOrm\Tests\Fixtures\FakeEntityHydrator;
use GraphqlOrm\Tests\Fixtures\FakeMetadataFactory;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;
use PHPUnit\Framework\TestCase;

final class GraphqlEntityRepositoryTest extends TestCase
{
    public function testCreateQueryBuilder(): void
    {
        $manager = $this->createManager([]);

        $repo = new GraphqlEntityRepository($manager, FakeEntity::class);

        self::assertInstanceOf(GraphqlQueryBuilder::class, $repo->createQueryBuilder());
    }

    public function testFindAllCallsFindBy(): void
    {
        $stdClass = new \stdClass();
        $hydrator = new FakeEntityHydrator($stdClass);

        $manager = $this->createManager([
            'data' => [
                'tasks' => [['id' => 1]],
            ],
        ], $hydrator);

        $repo = new GraphqlEntityRepository($manager, FakeEntity::class);

        self::assertSame([$stdClass], $repo->findAll());
    }

    public function testFindByHydratesList(): void
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

        $repo = new GraphqlEntityRepository($manager, FakeEntity::class);

        self::assertSame([$stdClass1, $stdClass2], $repo->findBy(['id' => 1]));
        self::assertSame(2, $hydrator->callCount);
    }

    public function testFindByHydratesSingleObject(): void
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

        $repo = new GraphqlEntityRepository($manager, FakeEntity::class);

        self::assertSame([$stdClass], $repo->findBy([]));
        self::assertSame(1, $hydrator->callCount);
    }

    public function testFindByReturnsEmptyWhenNoData(): void
    {
        $manager = $this->createManager([
            'data' => [],
        ]);

        $repo = new GraphqlEntityRepository($manager, FakeEntity::class);

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

        (new GraphqlEntityRepository($manager, FakeEntity::class))->findBy([]);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function createManager(array $response, ?EntityHydrator $hydrator = null): GraphqlManager
    {
        $metadataFactory = new FakeMetadataFactory(
            new GraphqlEntityMetadata(
                FakeEntity::class,
                'tasks',
                FakeRepository::class,
                [
                    new GraphqlFieldMetadata('id', 'id'),
                ],
                new GraphqlFieldMetadata('id', 'id')
            )
        );

        $hydrator ??= new FakeEntityHydrator();

        $manager = $this->createStub(GraphqlManager::class);

        $manager->method('getMetadataFactory')->willReturn($metadataFactory);
        $manager->method('getHydrator')->willReturn($hydrator);

        $manager
            ->method('execute')
            ->willReturnCallback(fn (QueryNode|string $_, callable $hydration) => $hydration($response, new GraphqlExecutionContext()));

        $manager
            ->method('getDialect')
            ->willReturn(new DefaultDialect());

        return $manager;
    }
}
