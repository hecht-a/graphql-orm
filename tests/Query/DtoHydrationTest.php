<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Exception\CastException;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\Dto\MisspelledFieldDto;
use GraphqlOrm\Tests\Fixtures\Dto\Priority;
use GraphqlOrm\Tests\Fixtures\Dto\RelationAsScalarDto;
use GraphqlOrm\Tests\Fixtures\Dto\Task;
use GraphqlOrm\Tests\Fixtures\Dto\TaskCard;
use GraphqlOrm\Tests\Fixtures\Dto\TaskLine;
use GraphqlOrm\Tests\Fixtures\Dto\TaskSummary;
use GraphqlOrm\Tests\Fixtures\Dto\User;
use GraphqlOrm\Tests\Fixtures\Dto\UserCard;
use GraphqlOrm\Tests\Fixtures\Dto\UserWithTasks;
use GraphqlOrm\Tests\Fixtures\Dto\UserWithTaskTitles;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * hydrateAs() hydrates read-only objects through their constructor, selecting the fields they map only.
 */
final class DtoHydrationTest extends TestCase
{
    private const TASK_ROWS = ['data' => ['tasks' => ['items' => [
        ['id' => 1, 'title' => 'Task A', 'due_date' => '2026-01-15T10:00:00+00:00', 'priority' => 'high', 'assignee' => ['id' => 7, 'name' => 'User A']],
        ['id' => 2, 'title' => 'Task B', 'due_date' => null, 'priority' => null, 'assignee' => null],
    ]]]];

    #[Test]
    public function itSelectsTheFieldsOfTheDtoOnly(): void
    {
        $graphql = $this->repository(Task::class)->createQueryBuilder()
            ->hydrateAs(TaskSummary::class)
            ->getQuery()
            ->getGraphQL();

        self::assertSame('query { tasks { items { id title due_date priority assignee { name id } } } }', self::flatten($graphql));
    }

    #[Test]
    public function itHydratesDtosWithTheValuesConverted(): void
    {
        $summaries = $this->repository(Task::class, new FakeGraphqlClient(self::TASK_ROWS))->createQueryBuilder()
            ->hydrateAs(TaskSummary::class)
            ->getQuery()
            ->getResult();

        self::assertIsArray($summaries);
        self::assertEquals(
            [
                new TaskSummary(1, 'Task A', new \DateTimeImmutable('2026-01-15T10:00:00+00:00'), Priority::High, 'User A'),
                new TaskSummary(2, 'Task B', null, null, null),
            ],
            $summaries,
        );
    }

    #[Test]
    public function itHydratesANestedDtoFromARelation(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['items' => [
            ['id' => 1, 'title' => 'Task A', 'assignee' => ['id' => 7, 'name' => 'User A', 'company' => ['id' => 3, 'name' => 'Company A']]],
            ['id' => 2, 'title' => 'Task B', 'assignee' => ['id' => 8, 'name' => 'User B', 'company' => null]],
            ['id' => 3, 'title' => 'Task C', 'assignee' => null],
        ]]]]);
        $query = $this->repository(Task::class, $client)->createQueryBuilder()->hydrateAs(TaskCard::class)->getQuery();

        self::assertSame('query { tasks { items { id title assignee { name company { name id } id } } } }', self::flatten($query->getGraphQL()));
        self::assertEquals(
            [
                new TaskCard('Task A', new UserCard('User A', 'Company A')),
                new TaskCard('Task B', new UserCard('User B')),
                new TaskCard('Task C', null),
            ],
            $query->getResult(),
        );
    }

    #[Test]
    public function itHydratesACollectionOfDtosDeclaredOnTheConstructor(): void
    {
        $client = new FakeGraphqlClient(['data' => ['users' => ['items' => [
            ['id' => 7, 'name' => 'User A', 'tasks' => ['items' => [['id' => 1, 'title' => 'Task A', 'done' => true], ['id' => 2, 'title' => 'Task B', 'done' => false]]]],
        ]]]]);
        $query = $this->repository(User::class, $client)->createQueryBuilder()->hydrateAs(UserWithTasks::class)->getQuery();

        self::assertSame('query { users { items { id name tasks { items { title done id } } } } }', self::flatten($query->getGraphQL()));
        self::assertEquals(
            [new UserWithTasks('User A', [new TaskLine('Task A', true), new TaskLine('Task B', false)])],
            $query->getResult(),
        );
    }

    #[Test]
    public function itHydratesACollectionOfDtosDeclaredOnThePromotedProperty(): void
    {
        $client = new FakeGraphqlClient(['data' => ['users' => ['items' => [
            ['id' => 7, 'name' => 'User A', 'tasks' => ['items' => [['id' => 1, 'title' => 'Task A', 'done' => true]]]],
        ]]]]);

        $users = $this->repository(User::class, $client)->createQueryBuilder()->hydrateAs(UserWithTaskTitles::class)->getQuery()->getResult();

        self::assertEquals([new UserWithTaskTitles('User A', [new TaskLine('Task A', true)])], $users);
    }

    #[Test]
    public function itKeysTheDtosByAFieldOfTheEntity(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['items' => [
            ['id' => 4, 'title' => 'Task A', 'done' => true],
            ['id' => 9, 'title' => 'Task B', 'done' => false],
        ]]]]);
        $query = $this->repository(Task::class, $client)->createQueryBuilder()
            ->indexBy('id')
            ->hydrateAs(TaskLine::class)
            ->getQuery();

        self::assertSame('query { tasks { items { id title done } } }', self::flatten($query->getGraphQL()));
        self::assertEquals([4 => new TaskLine('Task A', true), 9 => new TaskLine('Task B', false)], $query->getResult());
    }

    #[Test]
    public function itReturnsASingleDto(): void
    {
        $summary = $this->repository(Task::class, new FakeGraphqlClient(self::TASK_ROWS))->createQueryBuilder()
            ->hydrateAs(TaskSummary::class)
            ->getQuery()
            ->getOneOrNullResult();

        self::assertInstanceOf(TaskSummary::class, $summary);
        self::assertSame('Task A', $summary->title);
    }

    #[Test]
    public function itRejectsAParameterTheEntityDoesNotMap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"titel" is not a field of "GraphqlOrm\Tests\Fixtures\Dto\Task": did you mean "title"?');

        $this->repository(Task::class)->createQueryBuilder()->hydrateAs(MisspelledFieldDto::class);
    }

    #[Test]
    public function itRejectsARelationMappedToAScalar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"GraphqlOrm\Tests\Fixtures\Dto\RelationAsScalarDto::$assignee" maps the relation "assignee": type it with a class to hydrate, or map a field of the relation with #[MapFrom(\'assignee.name\')].');

        $this->repository(Task::class)->createQueryBuilder()->hydrateAs(RelationAsScalarDto::class);
    }

    #[Test]
    public function itRejectsASelectionAlongWithHydrateAs(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('hydrateAs() selects the fields of the DTO');

        $this->repository(Task::class)->createQueryBuilder()
            ->select('title')
            ->hydrateAs(TaskSummary::class)
            ->getQuery();
    }

    #[Test]
    public function itRejectsANullValueForANonNullableParameter(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['items' => [
            ['id' => 1, 'title' => null, 'due_date' => null, 'priority' => null, 'assignee' => null],
        ]]]]);

        $this->expectException(CastException::class);
        $this->expectExceptionMessage('"GraphqlOrm\Tests\Fixtures\Dto\TaskSummary::$title" cannot be null');

        $this->repository(Task::class, $client)->createQueryBuilder()->hydrateAs(TaskSummary::class)->getQuery()->getResult();
    }

    private static function flatten(string $graphql): string
    {
        return (string) preg_replace('/\s+/', ' ', $graphql);
    }

    /**
     * @template E of object
     *
     * @param class-string<E> $class
     *
     * @return GraphqlEntityRepository<E>
     */
    private function repository(string $class, FakeGraphqlClient $client = new FakeGraphqlClient()): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        $manager = new GraphqlManager(
            $metadataFactory,
            $client,
            new EntityHydrator($metadataFactory),
            new GraphqlOrmDataCollector(),
            1,
            new DataApiBuilderDialect(),
        );

        return new GraphqlEntityRepository($manager, $class);
    }
}
