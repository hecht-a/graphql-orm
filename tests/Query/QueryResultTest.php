<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\Entity\Task;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QueryResultTest extends TestCase
{
    private GraphqlOrmDataCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new GraphqlOrmDataCollector();
    }

    #[Test]
    public function itDoesNotAlterTheOriginalQueryWhenFetchingTheNextPage(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['tasks' => ['items' => [['id' => 1, 'title' => 'A']], 'hasNextPage' => true, 'endCursor' => 'c1']]],
            ['data' => ['tasks' => ['items' => [['id' => 2, 'title' => 'B']], 'hasNextPage' => false, 'endCursor' => 'c2']]],
        );
        $query = $this->createRepository($client, new DataApiBuilderDialect(), FakeTask::class)
            ->createQueryBuilder()
            ->paginate()
            ->limit(1)
            ->getQuery();
        $firstPage = $query->getResult();
        self::assertInstanceOf(PaginatedResult::class, $firstPage);

        $firstPage->next();

        self::assertStringNotContainsString('after', $query->getGraphQL());
    }

    #[Test]
    public function itReturnsAnEmptyPaginatedResultWhenTheRootIsNull(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => null]]);
        $query = $this->createRepository($client, new DataApiBuilderDialect(), FakeTask::class)
            ->createQueryBuilder()
            ->paginate()
            ->getQuery();

        $result = $query->getResult();

        self::assertInstanceOf(PaginatedResult::class, $result);
        self::assertSame([], $result->items);
        self::assertFalse($result->hasNextPage);
    }

    #[Test]
    public function itLimitsTheRequestToOneResultForGetOneOrNullResult(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => [['id' => 1, 'title' => 'A']]]]);
        $query = $this->createRepository($client, new DefaultDialect(), FakeTask::class)
            ->createQueryBuilder()
            ->getQuery();

        $task = $query->getOneOrNullResult();

        self::assertInstanceOf(FakeTask::class, $task);
        self::assertStringContainsString('tasks(first: 1)', $client->lastQuery);
        self::assertStringNotContainsString('first', $query->getGraphQL());
    }

    #[Test]
    public function itKeepsAnExplicitLimitForGetOneOrNullResult(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => [['id' => 1, 'title' => 'A']]]]);
        $query = $this->createRepository($client, new DefaultDialect(), FakeTask::class)
            ->createQueryBuilder()
            ->limit(5)
            ->getQuery();

        $query->getOneOrNullResult();

        self::assertStringContainsString('tasks(first: 5)', $client->lastQuery);
    }

    #[Test]
    public function itRecordsVariablesInTheProfiler(): void
    {
        $client = new FakeGraphqlClient(['data' => []]);
        $manager = $this->createManager($client, new DefaultDialect());

        $manager->execute('query($id: Int) { tasks(id: $id) { id } }', fn () => [], variables: ['id' => 1]);

        self::assertSame(['id' => 1], $this->collector->getQueries()[0]['variables']);
    }

    #[Test]
    public function itRecordsTheRootResultCountAndTheHydrationDepthInTheProfiler(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => [
            ['id' => 1, 'title' => 'A', 'user' => ['id' => 10, 'name' => 'User A']],
            ['id' => 2, 'title' => 'B', 'user' => ['id' => 10, 'name' => 'User A']],
        ]]]);
        $repository = $this->createRepository($client, new DefaultDialect(), Task::class);

        $repository->findAll();

        $recorded = $this->collector->getQueries()[0];
        self::assertSame(2, $recorded['hydrated_count']);
        self::assertSame(1, $recorded['depth_used']);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $entityClass
     *
     * @return GraphqlEntityRepository<T>
     */
    private function createRepository(FakeGraphqlClient $client, GraphqlQueryDialect $dialect, string $entityClass): GraphqlEntityRepository
    {
        return new GraphqlEntityRepository($this->createManager($client, $dialect), $entityClass);
    }

    private function createManager(FakeGraphqlClient $client, GraphqlQueryDialect $dialect): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager(
            $metadataFactory,
            $client,
            new EntityHydrator($metadataFactory),
            $this->collector,
            2,
            $dialect,
        );
    }
}
