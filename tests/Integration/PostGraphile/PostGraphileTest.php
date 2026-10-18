<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\PostGraphile;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Dialect\PostGraphileDialect;
use GraphqlOrm\Exception\GraphqlRequestException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\GraphqlQuery;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use GraphqlOrm\Tests\Integration\Dto\TaskSummary;
use GraphqlOrm\Tests\Integration\Dto\TaskTitle;
use GraphqlOrm\Tests\Integration\Dto\UserWithTaskTitles;
use GraphqlOrm\Tests\Integration\IntegrationTestCase;
use GraphqlOrm\Tests\Integration\PostGraphile\Entity\RelayNote;
use GraphqlOrm\Tests\Integration\PostGraphile\Entity\RelayTask;
use GraphqlOrm\Tests\Integration\PostGraphile\Entity\RelayUser;
use GraphqlOrm\Tests\Integration\WritesEntities;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * PostGraphileDialect: the find methods (condition) and ordering (orderBy enums) on top of the Relay connections.
 */
#[Group('integration')]
final class PostGraphileTest extends IntegrationTestCase
{
    use WritesEntities;

    protected static function endpointVariable(): string
    {
        return 'GRAPHQL_ORM_POSTGRAPHILE_ENDPOINT';
    }

    protected function dialect(): GraphqlQueryDialect
    {
        return new PostGraphileDialect();
    }

    #[Test]
    public function itCountsEntities(): void
    {
        self::assertSame(4, $this->repository(RelayTask::class)->count());
        self::assertSame(3, $this->repository(RelayTask::class)->count(['done' => false]));
        self::assertSame(1, $this->repository(RelayTask::class)->count(['done' => true]));
    }

    #[Test]
    public function itLoadsTheRelationsNamedByWithBeyondTheMaxDepth(): void
    {
        $task = $this->repository(RelayTask::class, maxDepth: 1)->createQueryBuilder()
            ->with('user.tasks')
            ->orderBy('id', Direction::Asc)
            ->limit(1)
            ->getQuery()
            ->getOneOrNullResult();

        self::assertInstanceOf(RelayTask::class, $task);
        $titles = array_map(static fn (RelayTask $task) => $task->title, $task->user->tasks ?? []);
        sort($titles);
        self::assertSame(['Review the tests', 'Write the docs'], $titles);
    }

    #[Test]
    public function itHydratesDtosThroughTheConnections(): void
    {
        $summaries = $this->repository(RelayTask::class)->createQueryBuilder()
            ->orderBy('id', Direction::Asc)
            ->hydrateAs(TaskSummary::class)
            ->getQuery()
            ->getResult();
        $user = $this->repository(RelayUser::class)->createQueryBuilder()
            ->orderBy('id', Direction::Asc)
            ->hydrateAs(UserWithTaskTitles::class)
            ->getQuery()
            ->getOneOrNullResult();

        self::assertIsArray($summaries);
        self::assertEquals(new TaskSummary(3, 'Release the bundle', new \DateTimeImmutable('2026-02-01T09:00:00Z'), 'User B'), $summaries[2]);
        self::assertInstanceOf(UserWithTaskTitles::class, $user);
        self::assertEqualsCanonicalizing([new TaskTitle('Write the docs', false), new TaskTitle('Review the tests', true)], $user->tasks);
    }

    #[Test]
    public function itFindsEntitiesByCondition(): void
    {
        $task = $this->repository(RelayTask::class)->find(3);

        self::assertSame('Release the bundle', $task?->title);
        self::assertSame('User B', $task->user?->name);
        self::assertSame([2], self::ids($this->repository(RelayTask::class)->findBy(['done' => true])));
        self::assertSame([4], self::ids($this->repository(RelayTask::class)->findBy(['userId' => null])));
    }

    #[Test]
    public function itOrdersAndLimitsResults(): void
    {
        $tasks = $this->repository(RelayTask::class)->findBy([], ['title' => Direction::Desc], 2);
        $last = $this->repository(RelayTask::class)->findOneBy(['done' => false], ['id' => Direction::Desc]);
        $byDueDate = $this->repository(RelayTask::class)->findBy(['done' => false], ['dueDate' => Direction::Asc, 'id' => Direction::Asc]);

        self::assertSame(['Write the docs', 'Review the tests'], array_map(fn (RelayTask $task) => $task->title, $tasks));
        self::assertSame(4, $last?->id);
        self::assertSame([1, 3, 4], self::ids($byDueDate));
    }

    #[Test]
    public function itIteratesOverEveryPage(): void
    {
        $tasks = $this->repository(RelayTask::class)->createQueryBuilder()->orderBy('id', Direction::Desc)->limit(3)->paginate()->getQuery()->toIterable();

        self::assertSame([4, 3, 2, 1], self::ids($tasks));
    }

    #[Test]
    public function itExplainsAQueryOnAMisspelledField(): void
    {
        try {
            $this->repository(RelayTask::class)->findBy(['titel' => 'A']);
            self::fail('A misspelled field must fail.');
        } catch (GraphqlRequestException $exception) {
            self::assertStringContainsString('titel', $exception->getMessage());
            self::assertStringContainsString(\sprintf('Field "titel" is not mapped on %s: did you mean "title"?', RelayTask::class), $exception->getMessage());
        }
    }

    #[Test]
    public function itRejectsAListOfValues(): void
    {
        $this->expectException(LogicException::class);

        $this->repository(RelayTask::class)->findBy(['id' => [1, 3]]);
    }

    #[Test]
    public function itSendsQueriesInOneDocument(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $manager = $this->manager($collector);

        [$tasks, $users] = GraphqlQuery::batch(
            (new GraphqlEntityRepository($manager, RelayTask::class))->createQueryBuilder()->orderBy('id', Direction::Desc)->getQuery(),
            (new GraphqlEntityRepository($manager, RelayUser::class))->createQueryBuilder()->getQuery(),
        );

        self::assertSame(1, $collector->getQueryCount());
        self::assertIsArray($tasks);
        self::assertSame([4, 3, 2, 1], self::ids($tasks));
        self::assertIsArray($users);
        self::assertCount(2, $users);
    }

    #[Test]
    public function itNavigatesOrderedPages(): void
    {
        $firstPage = $this->repository(RelayTask::class)->createQueryBuilder()->orderBy('id', Direction::Desc)->limit(3)->paginate()->getQuery()->getResult();
        self::assertInstanceOf(PaginatedResult::class, $firstPage);

        $secondPage = $firstPage->next();

        self::assertSame([4, 3, 2], self::ids($firstPage->items));
        self::assertSame([1], self::ids($secondPage->items ?? []));
    }

    #[Test]
    public function itSendsTheConditionAndTheOrderingAsTypedVariables(): void
    {
        $resolver = new RootArgumentTypeResolver($this->client, new ArrayAdapter(), 60);

        $tasks = $this->repository(RelayTask::class, $resolver)->findBy(['done' => false], ['id' => Direction::Asc], 2);

        self::assertSame([1, 3], self::ids($tasks));
        self::assertSame('TaskCondition', $resolver->argumentTypes('allTasks')['condition'] ?? null);
    }

    #[Test]
    public function itLoadsLazyRelationsOneQueryEach(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $tasks = $this->repository(RelayTask::class, maxDepth: 0, lazyLoading: true, collector: $collector)->findAll();

        $names = array_map(fn (RelayTask $task) => $task->user?->name, $tasks);

        self::assertSame(['User A', 'User A', 'User B', null], $names);
        self::assertSame(3, $collector->getQueryCount());
    }

    #[Test]
    public function itRejectsTheQueryBuilderFilters(): void
    {
        $qb = $this->repository(RelayTask::class)->createQueryBuilder();

        $this->expectException(LogicException::class);
        $qb->where($qb->expr()->eq('title', 'A'))->getQuery();
    }

    protected function noteAndUser(): array
    {
        return [RelayNote::class, RelayUser::class];
    }
}
