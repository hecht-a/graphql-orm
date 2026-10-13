<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Event\QueryExecutedEvent;
use GraphqlOrm\Event\QueryFailedEvent;
use GraphqlOrm\Event\QueryStartedEvent;
use GraphqlOrm\Exception\GraphqlRequestException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\GraphqlQuery;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Test\MockGraphqlServer;
use GraphqlOrm\Tests\Fixtures\FakeEventDispatcher;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Integration\Entity\Task;
use GraphqlOrm\Tests\Integration\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConcurrentQueriesTest extends TestCase
{
    private const TASKS = ['tasks' => ['items' => [['id' => 1, 'title' => 'A', 'done' => false]]]];

    private const USERS = ['users' => ['items' => [['id' => 1, 'name' => 'User A']]]];

    #[Test]
    public function itSendsEveryQueryBeforeReadingTheResponses(): void
    {
        $events = new FakeEventDispatcher();
        $manager = $this->manager((new MockGraphqlServer())->respond(self::TASKS)->respond(self::USERS), $events);

        [$tasks, $users] = GraphqlQuery::concurrently(
            (new GraphqlEntityRepository($manager, Task::class))->createQueryBuilder()->getQuery(),
            (new GraphqlEntityRepository($manager, User::class))->createQueryBuilder()->getQuery(),
        );

        self::assertIsArray($tasks);
        self::assertInstanceOf(Task::class, $tasks[0]);
        self::assertIsArray($users);
        self::assertInstanceOf(User::class, $users[0]);
        self::assertSame(
            [QueryStartedEvent::class, QueryStartedEvent::class, QueryExecutedEvent::class, QueryExecutedEvent::class],
            array_map(fn (object $event) => $event::class, $events->events),
        );
    }

    #[Test]
    public function itCompletesEveryQueryBeforeThrowingTheFirstFailure(): void
    {
        $events = new FakeEventDispatcher();
        $server = (new MockGraphqlServer())
            ->respond(self::TASKS)
            ->respondWithErrors([['message' => 'Forbidden']])
            ->respondWithHttpStatus(503);
        $manager = $this->manager($server, $events);
        $tasks = new GraphqlEntityRepository($manager, Task::class);

        try {
            GraphqlQuery::concurrently($tasks->createQueryBuilder()->getQuery(), $tasks->createQueryBuilder()->getQuery(), $tasks->createQueryBuilder()->getQuery());
            self::fail('A failing query must fail.');
        } catch (GraphqlRequestException $exception) {
            self::assertStringContainsString('Forbidden', $exception->getMessage());
        }

        self::assertSame(
            [QueryExecutedEvent::class, QueryFailedEvent::class, QueryFailedEvent::class],
            array_values(array_map(fn (object $event) => $event::class, array_filter($events->events, fn (object $event) => !$event instanceof QueryStartedEvent))),
        );
    }

    #[Test]
    public function itPointsOutTheUnmappedFieldsOfAFailingQuery(): void
    {
        $manager = $this->manager((new MockGraphqlServer())->respondWithErrors([['message' => 'Unknown field']]));
        $qb = (new GraphqlEntityRepository($manager, Task::class))->createQueryBuilder();

        $this->expectExceptionMessage(\sprintf('Field "titel" is not mapped on %s: did you mean "title"?', Task::class));

        GraphqlQuery::concurrently($qb->where($qb->expr()->eq('titel', 'A'))->getQuery());
    }

    #[Test]
    public function itRunsTheQueriesOneAfterTheOtherWithAClientThatCannotSendConcurrently(): void
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, new FakeGraphqlClient(['data' => self::TASKS], ['data' => self::USERS]), new EntityHydrator($metadataFactory), null, 2, new DataApiBuilderDialect());

        [$tasks, $users] = GraphqlQuery::concurrently(
            (new GraphqlEntityRepository($manager, Task::class))->createQueryBuilder()->getQuery(),
            (new GraphqlEntityRepository($manager, User::class))->createQueryBuilder()->getQuery(),
        );

        self::assertIsArray($tasks);
        self::assertInstanceOf(Task::class, $tasks[0]);
        self::assertIsArray($users);
        self::assertInstanceOf(User::class, $users[0]);
    }

    private function manager(MockGraphqlServer $server, ?FakeEventDispatcher $events = null): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager(
            $metadataFactory,
            new GraphqlClient($server, 'https://graphql.example.com/graphql'),
            new EntityHydrator($metadataFactory),
            null,
            2,
            new DataApiBuilderDialect(),
            eventDispatcher: $events,
        );
    }
}
