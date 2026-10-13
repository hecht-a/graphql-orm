<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Exception\NonUniqueResultException;
use GraphqlOrm\Exception\NoResultException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\Entity\Task;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeUserWithCollection;
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
    public function itGoesBackToTheFirstPageFromAPaginationStartedAtACursor(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['tasks' => ['items' => [['id' => 2, 'title' => 'B']], 'hasNextPage' => false, 'endCursor' => 'c2']]],
            ['data' => ['tasks' => ['items' => [['id' => 1, 'title' => 'A']], 'hasNextPage' => true, 'endCursor' => 'c1']]],
        );
        $secondPage = $this->createRepository($client, new DataApiBuilderDialect(), FakeTask::class)
            ->createQueryBuilder()
            ->limit(1)
            ->paginate('c1')
            ->getQuery()
            ->getResult();
        self::assertInstanceOf(PaginatedResult::class, $secondPage);

        $firstPage = $secondPage->previous();

        self::assertSame(1, $firstPage?->items[0]->id);
        self::assertStringNotContainsString('after', $client->lastQuery);
    }

    #[Test]
    public function itHasNoNextPageWithoutAnEndCursor(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['tasks' => ['items' => [['id' => 1, 'title' => 'A']], 'hasNextPage' => true, 'endCursor' => null]]],
        );
        $firstPage = $this->createRepository($client, new DataApiBuilderDialect(), FakeTask::class)
            ->createQueryBuilder()
            ->paginate()
            ->getQuery()
            ->getResult();
        self::assertInstanceOf(PaginatedResult::class, $firstPage);

        $nextPage = $firstPage->next();

        self::assertNull($nextPage);
        self::assertSame(1, $client->callCount);
    }

    #[Test]
    public function itRejectsACollectionRowThatIsNotAnObject(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['items' => ['not an object']]]]);
        $repository = $this->createRepository($client, new DataApiBuilderDialect(), FakeTask::class);

        $this->expectException(InvalidGraphqlResponseException::class);
        $repository->findAll();
    }

    #[Test]
    public function itRejectsANestedCollectionRowThatIsNotAnObject(): void
    {
        $client = new FakeGraphqlClient(['data' => ['users' => ['items' => [['id' => 1, 'tasks' => ['items' => [42]]]]]]]);
        $repository = $this->createRepository($client, new DataApiBuilderDialect(), FakeUserWithCollection::class);

        $this->expectException(InvalidGraphqlResponseException::class);
        $repository->findAll();
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
    public function itReturnsTheSingleResultRequestingTwoToTellItIsUnique(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => [['id' => 1, 'title' => 'A']]]]);
        $query = $this->createRepository($client, new DefaultDialect(), FakeTask::class)->createQueryBuilder()->getQuery();

        $task = $query->getSingleResult();

        self::assertSame(1, $task->id);
        self::assertStringContainsString('tasks(first: 2)', $client->lastQuery);
    }

    #[Test]
    public function itThrowsWhenTheSingleResultIsMissing(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => []]]);
        $query = $this->createRepository($client, new DefaultDialect(), FakeTask::class)->createQueryBuilder()->getQuery();

        $this->expectException(NoResultException::class);
        $this->expectExceptionMessage(FakeTask::class);

        $query->getSingleResult();
    }

    #[Test]
    public function itThrowsWhenTheSingleResultIsNotUnique(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => [['id' => 1, 'title' => 'A'], ['id' => 2, 'title' => 'B']]]]);
        $query = $this->createRepository($client, new DefaultDialect(), FakeTask::class)->createQueryBuilder()->getQuery();

        $this->expectException(NonUniqueResultException::class);
        $this->expectExceptionMessage(FakeTask::class);

        $query->getSingleResult();
    }

    #[Test]
    public function itKeepsAnExplicitLimitForGetSingleResult(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => [['id' => 1, 'title' => 'A']]]]);
        $query = $this->createRepository($client, new DefaultDialect(), FakeTask::class)->createQueryBuilder()->limit(5)->getQuery();

        $query->getSingleResult();

        self::assertStringContainsString('tasks(first: 5)', $client->lastQuery);
    }

    #[Test]
    public function itReturnsTheSingleResultOfAPaginatedQuery(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['items' => [['id' => 1, 'title' => 'A']], 'hasNextPage' => false, 'endCursor' => 'c1']]]);
        $query = $this->createRepository($client, new DataApiBuilderDialect(), FakeTask::class)->createQueryBuilder()->limit(10)->paginate()->getQuery();

        self::assertSame(1, $query->getSingleResult()->id);
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
