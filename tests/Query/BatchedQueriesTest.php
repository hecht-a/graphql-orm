<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Exception\GraphqlRequestException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\GraphqlQuery;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Test\MockGraphqlServer;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Integration\Entity\Task;
use GraphqlOrm\Tests\Integration\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BatchedQueriesTest extends TestCase
{
    private const TASKS = ['items' => [['id' => 1, 'title' => 'A', 'done' => false, 'due_date' => null, 'user' => null]]];

    private const USERS = ['items' => [['id' => 7, 'name' => 'User A', 'tasks' => ['items' => []]]]];

    #[Test]
    public function itSendsTheQueriesInOneDocument(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => self::TASKS, 'users' => self::USERS]]);
        $manager = $this->manager($client);
        $tasks = (new GraphqlEntityRepository($manager, Task::class))->createQueryBuilder();

        [$taskResult, $userResult] = GraphqlQuery::batch(
            $tasks->where($tasks->expr()->eq('done', false))->limit(5)->getQuery(),
            (new GraphqlEntityRepository($manager, User::class))->createQueryBuilder()->getQuery(),
        );

        self::assertSame(1, $client->callCount);
        $query = (string) preg_replace('/\s+/', ' ', $client->lastQuery);
        self::assertStringContainsString('tasks(first: 5, filter: { done: { eq: false } })', $query);
        self::assertStringContainsString('users {', $query);
        self::assertIsArray($taskResult);
        self::assertInstanceOf(Task::class, $taskResult[0]);
        self::assertIsArray($userResult);
        self::assertInstanceOf(User::class, $userResult[0]);
    }

    #[Test]
    public function itFailsTheQueryWhoseRootHasAnErrorAndHydratesTheOthers(): void
    {
        $server = (new MockGraphqlServer())->respondWithErrors(
            [['message' => 'Forbidden', 'path' => ['users']]],
            ['tasks' => self::TASKS, 'users' => null],
        );
        $manager = $this->manager(new GraphqlClient($server, 'https://graphql.example.com/graphql'));
        $users = (new GraphqlEntityRepository($manager, User::class))->createQueryBuilder();

        try {
            GraphqlQuery::batch(
                (new GraphqlEntityRepository($manager, Task::class))->createQueryBuilder()->getQuery(),
                $users->where($users->expr()->eq('nmae', 'A'))->getQuery(),
            );
            self::fail('The failing query must fail the batch.');
        } catch (GraphqlRequestException $exception) {
            self::assertStringContainsString('Forbidden', $exception->getMessage());
            self::assertStringContainsString(\sprintf('Field "nmae" is not mapped on %s: did you mean "name"?', User::class), $exception->getMessage());
        }
    }

    #[Test]
    public function itRejectsTwoQueriesOnTheSameRoot(): void
    {
        $tasks = new GraphqlEntityRepository($this->manager(new FakeGraphqlClient()), Task::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('concurrently()');

        GraphqlQuery::batch($tasks->createQueryBuilder()->getQuery(), $tasks->createQueryBuilder()->getQuery());
    }

    #[Test]
    public function itRejectsAPaginatedQuery(): void
    {
        $manager = $this->manager(new FakeGraphqlClient());

        $this->expectException(LogicException::class);

        GraphqlQuery::batch(
            (new GraphqlEntityRepository($manager, Task::class))->createQueryBuilder()->limit(5)->paginate()->getQuery(),
            (new GraphqlEntityRepository($manager, User::class))->createQueryBuilder()->getQuery(),
        );
    }

    #[Test]
    public function itRejectsQueriesOfAnotherManager(): void
    {
        $this->expectException(LogicException::class);

        GraphqlQuery::batch(
            (new GraphqlEntityRepository($this->manager(new FakeGraphqlClient()), Task::class))->createQueryBuilder()->getQuery(),
            (new GraphqlEntityRepository($this->manager(new FakeGraphqlClient()), User::class))->createQueryBuilder()->getQuery(),
        );
    }

    #[Test]
    public function itRejectsAQueryWrittenByHand(): void
    {
        $manager = $this->manager(new FakeGraphqlClient());

        $this->expectException(LogicException::class);

        GraphqlQuery::batch(
            (new GraphqlEntityRepository($manager, Task::class))->createQueryBuilder()->setGraphQL('query { tasks { items { id } } }')->getQuery(),
            (new GraphqlEntityRepository($manager, User::class))->createQueryBuilder()->getQuery(),
        );
    }

    #[Test]
    public function itReturnsNothingForNoQuery(): void
    {
        self::assertSame([], GraphqlQuery::batch());
    }

    private function manager(GraphqlClientInterface $client): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 2, new DataApiBuilderDialect());
    }
}
