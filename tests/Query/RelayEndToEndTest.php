<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\Dialect\RelayDialect;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeUserWithCollection;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RelayEndToEndTest extends TestCase
{
    #[Test]
    public function itHydratesTheNodesOfAConnection(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['edges' => [
            ['node' => ['id' => 1, 'title' => 'A']],
            ['node' => ['id' => 2, 'title' => 'B']],
        ]]]]);

        $tasks = $this->repository($client, FakeTask::class)->findAll();

        self::assertSame(['A', 'B'], array_map(fn (FakeTask $task) => $task->title, $tasks));
    }

    #[Test]
    public function itHydratesNestedConnections(): void
    {
        $client = new FakeGraphqlClient(['data' => ['users' => ['edges' => [
            ['node' => ['id' => 1, 'tasks' => ['edges' => [['node' => ['id' => 10, 'title' => 'A']]]]]],
        ]]]]);

        $users = $this->repository($client, FakeUserWithCollection::class)->findAll();

        self::assertSame(10, $users[0]->tasks[0]->id);
        self::assertStringContainsString("tasks {\n          edges {\n            node {", $client->lastQuery);
    }

    #[Test]
    public function itNavigatesPagesWithThePageInfo(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['tasks' => ['edges' => [['node' => ['id' => 1, 'title' => 'A']]], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'c1']]]],
            ['data' => ['tasks' => ['edges' => [['node' => ['id' => 2, 'title' => 'B']]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'c2']]]],
        );

        $firstPage = $this->repository($client, FakeTask::class)->createQueryBuilder()->limit(1)->paginate()->getQuery()->getResult();
        self::assertInstanceOf(PaginatedResult::class, $firstPage);
        $secondPage = $firstPage->next();

        self::assertTrue($firstPage->hasNextPage);
        self::assertSame('c1', $firstPage->endCursor);
        self::assertSame(2, $secondPage?->items[0]->id);
        self::assertFalse($secondPage->hasNextPage);
        self::assertStringContainsString('tasks(first: 1, after: "c1")', $client->lastQuery);
    }

    #[Test]
    public function itIteratesOverEveryPage(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['tasks' => ['edges' => [['node' => ['id' => 1, 'title' => 'A']], ['node' => ['id' => 2, 'title' => 'B']]], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'c2']]]],
            ['data' => ['tasks' => ['edges' => [['node' => ['id' => 3, 'title' => 'C']]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'c3']]]],
        );

        $tasks = $this->repository($client, FakeTask::class)->createQueryBuilder()->limit(2)->paginate()->getQuery()->toIterable();

        self::assertSame([1, 2, 3], array_map(fn (FakeTask $task) => $task->id, iterator_to_array($tasks)));
        self::assertSame(2, $client->callCount);
        self::assertStringContainsString('tasks(first: 2, after: "c2")', $client->lastQuery);
    }

    #[Test]
    public function itFetchesTheNextPageOnlyOnceTheCurrentOneIsRead(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['tasks' => ['edges' => [['node' => ['id' => 1, 'title' => 'A']]], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'c1']]]],
            ['data' => ['tasks' => ['edges' => [['node' => ['id' => 2, 'title' => 'B']]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'c2']]]],
        );

        $tasks = $this->repository($client, FakeTask::class)->createQueryBuilder()->limit(1)->paginate()->getQuery()->toIterable();

        self::assertSame(1, $tasks->current()->id);
        self::assertSame(1, $client->callCount);
    }

    #[Test]
    public function itNeedsAPaginatedQueryToIterate(): void
    {
        $query = $this->repository(new FakeGraphqlClient(), FakeTask::class)->createQueryBuilder()->limit(100)->getQuery();

        $this->expectException(LogicException::class);

        $query->toIterable()->current();
    }

    #[Test]
    public function itReturnsTheNodesAsArrays(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['edges' => [['node' => ['id' => 1, 'title' => 'A']]]]]]);

        $rows = $this->repository($client, FakeTask::class)->createQueryBuilder()->getQuery()->getArrayResult();

        self::assertSame([['id' => 1, 'title' => 'A']], $rows);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $entityClass
     *
     * @return GraphqlEntityRepository<T>
     */
    private function repository(FakeGraphqlClient $client, string $entityClass): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 2, new RelayDialect());

        return new GraphqlEntityRepository($manager, $entityClass);
    }
}
