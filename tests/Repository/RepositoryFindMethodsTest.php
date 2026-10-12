<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Repository;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityWithoutIdentifier;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RepositoryFindMethodsTest extends TestCase
{
    private const DAB_TASKS = ['data' => ['tasks' => ['items' => [['id' => 1, 'title' => 'A'], ['id' => 2, 'title' => 'B']]]]];

    private const DAB_NO_TASK = ['data' => ['tasks' => ['items' => []]]];

    #[Test]
    public function itFiltersFindByCriteriaWithTheDialect(): void
    {
        $client = new FakeGraphqlClient(self::DAB_TASKS);

        $tasks = $this->repository($client, new DataApiBuilderDialect())->findBy(['title' => 'A']);

        self::assertStringContainsString('tasks(filter: { title: { eq: "A" } })', $client->lastQuery);
        self::assertCount(2, $tasks);
    }

    #[Test]
    public function itKeepsCriteriaAsRootArgumentsWithTheDefaultDialect(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => [['id' => 1, 'title' => 'A']]]]);

        $this->repository($client, new DefaultDialect())->findBy(['title' => 'A']);

        self::assertStringContainsString('tasks(title: "A")', $client->lastQuery);
    }

    #[Test]
    public function itOrdersAndLimitsFindBy(): void
    {
        $client = new FakeGraphqlClient(self::DAB_TASKS);

        $this->repository($client, new DataApiBuilderDialect())->findBy([], ['title' => Direction::DESC], 5);

        self::assertStringContainsString('first: 5', $client->lastQuery);
        self::assertStringContainsString('orderBy: { title: DESC }', $client->lastQuery);
    }

    #[Test]
    public function itRejectsAnOrderingThatIsNotADirection(): void
    {
        $repository = $this->repository(new FakeGraphqlClient(self::DAB_TASKS), new DataApiBuilderDialect());

        $this->expectException(InvalidArgumentException::class);
        $repository->findBy([], ['title' => 'DESC']);
    }

    #[Test]
    public function itRejectsOrderingWithTheDefaultDialect(): void
    {
        $repository = $this->repository(new FakeGraphqlClient(), new DefaultDialect());

        $this->expectException(LogicException::class);
        $repository->findBy([], ['title' => Direction::ASC]);
    }

    #[Test]
    public function itReturnsTheFirstEntityMatchingFindOneBy(): void
    {
        $client = new FakeGraphqlClient(self::DAB_TASKS);

        $task = $this->repository($client, new DataApiBuilderDialect())->findOneBy(['title' => 'A']);

        self::assertInstanceOf(FakeTask::class, $task);
        self::assertSame(1, $task->id);
        self::assertStringContainsString('first: 1', $client->lastQuery);
    }

    #[Test]
    public function itReturnsNullWhenFindOneByMatchesNothing(): void
    {
        $repository = $this->repository(new FakeGraphqlClient(self::DAB_NO_TASK), new DataApiBuilderDialect());

        $task = $repository->findOneBy(['title' => 'Z']);

        self::assertNull($task);
    }

    #[Test]
    public function itFindsAnEntityByItsIdentifier(): void
    {
        $client = new FakeGraphqlClient(self::DAB_TASKS);

        $task = $this->repository($client, new DataApiBuilderDialect())->find(1);

        self::assertInstanceOf(FakeTask::class, $task);
        self::assertStringContainsString('filter: { id: { eq: 1 } }', $client->lastQuery);
    }

    #[Test]
    public function itRejectsFindOnAnEntityWithoutIdentifier(): void
    {
        $repository = new GraphqlEntityRepository($this->manager(new FakeGraphqlClient(), new DefaultDialect()), FakeEntityWithoutIdentifier::class);

        $this->expectException(LogicException::class);
        $repository->find(1);
    }

    /**
     * @return GraphqlEntityRepository<FakeTask>
     */
    private function repository(FakeGraphqlClient $client, GraphqlQueryDialect $dialect): GraphqlEntityRepository
    {
        return new GraphqlEntityRepository($this->manager($client, $dialect), FakeTask::class);
    }

    /**
     * @return GraphqlManager<object>
     */
    private function manager(FakeGraphqlClient $client, GraphqlQueryDialect $dialect): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager(
            $metadataFactory,
            $client,
            new EntityHydrator($metadataFactory),
            new GraphqlOrmDataCollector(),
            2,
            $dialect,
        );
    }
}
