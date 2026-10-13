<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Hydrator;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityWithLazyScalar;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Integration\Entity\LazyTask;
use GraphqlOrm\Tests\Integration\Entity\Task;
use GraphqlOrm\Tests\Integration\Entity\UserWithLazyTasks;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relation mapped with fetch: Fetch::Lazy is selected by its identifier only and loaded on first access.
 */
final class LazyFetchTest extends TestCase
{
    private const LAZY_TASKS = ['data' => ['tasks' => ['items' => [
        ['id' => 1, 'title' => 'A', 'user' => ['id' => 7]],
        ['id' => 2, 'title' => 'B', 'user' => ['id' => 8]],
    ]]]];

    private const USERS = ['data' => ['users' => ['items' => [
        ['id' => 7, 'name' => 'User A', 'tasks' => ['items' => []]],
        ['id' => 8, 'name' => 'User B', 'tasks' => ['items' => []]],
    ]]]];

    #[Test]
    public function itSelectsALazyRelationByItsIdentifierOnly(): void
    {
        $client = new FakeGraphqlClient(self::LAZY_TASKS);

        $this->repository($client, LazyTask::class)->findAll();

        $query = (string) preg_replace('/\s+/', ' ', $client->lastQuery);
        self::assertStringContainsString('user { id }', $query);
        self::assertStringNotContainsString('name', $query);
    }

    #[Test]
    public function itLoadsTheLazyRelationsOnFirstAccessInOneQuery(): void
    {
        $client = new FakeGraphqlClient(self::LAZY_TASKS, self::USERS);
        $tasks = $this->repository($client, LazyTask::class)->findAll();

        self::assertSame(1, $client->callCount);
        self::assertSame(7, $tasks[0]->user?->id);
        self::assertSame(1, $client->callCount);

        self::assertSame('User A', $tasks[0]->user->name);
        self::assertSame('User B', $tasks[1]->user?->name);
        self::assertSame(2, $client->callCount);
        self::assertStringContainsString('id: { in: [7, 8] }', (string) preg_replace('/\s+/', ' ', $client->lastQuery));
    }

    #[Test]
    public function itSelectsALazyCollectionByTheIdentifiersOfItsItems(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['users' => ['items' => [['id' => 7, 'name' => 'User A', 'tasks' => ['items' => [['id' => 1], ['id' => 2]]]]]]]],
            ['data' => ['tasks' => ['items' => [
                ['id' => 1, 'title' => 'A', 'done' => false, 'due_date' => null, 'user' => null],
                ['id' => 2, 'title' => 'B', 'done' => true, 'due_date' => null, 'user' => null],
            ]]]],
        );

        $user = $this->repository($client, UserWithLazyTasks::class)->findAll()[0];

        self::assertStringContainsString('tasks { items { id } }', (string) preg_replace('/\s+/', ' ', $client->queries[0]));
        self::assertCount(2, $user->tasks);
        self::assertInstanceOf(Task::class, $user->tasks[1]);
        self::assertSame('B', $user->tasks[1]->title);
        self::assertSame(2, $client->callCount);
    }

    #[Test]
    public function itLoadsALazyRelationSelectedExplicitlyWithTheQuery(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['items' => [['id' => 1, 'user' => ['id' => 7, 'name' => 'User A']]]]]]);

        $tasks = $this->repository($client, LazyTask::class)->createQueryBuilder()->select('user.name')->getQuery()->getResult();

        self::assertIsArray($tasks);
        self::assertSame('User A', $tasks[0]->user?->name);
        self::assertSame(1, $client->callCount);
    }

    #[Test]
    public function itRejectsALazyFieldThatIsNoRelation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new GraphqlEntityMetadataFactory())->getMetadata(FakeEntityWithLazyScalar::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return GraphqlEntityRepository<T>
     */
    private function repository(FakeGraphqlClient $client, string $class): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 2, new DataApiBuilderDialect());

        return new GraphqlEntityRepository($manager, $class);
    }
}
