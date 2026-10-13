<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\PostGraphile;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Dialect\PostGraphileDialect;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use GraphqlOrm\Tests\Integration\IntegrationTestCase;
use GraphqlOrm\Tests\Integration\PostGraphile\Entity\RelayTask;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * PostGraphileDialect: the find methods (condition) and ordering (orderBy enums) on top of the Relay connections.
 */
#[Group('integration')]
final class PostGraphileTest extends IntegrationTestCase
{
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
        $tasks = $this->repository(RelayTask::class)->findBy([], ['title' => Direction::DESC], 2);
        $last = $this->repository(RelayTask::class)->findOneBy(['done' => false], ['id' => Direction::DESC]);
        $byDueDate = $this->repository(RelayTask::class)->findBy(['done' => false], ['dueDate' => Direction::ASC, 'id' => Direction::ASC]);

        self::assertSame(['Write the docs', 'Review the tests'], array_map(fn (RelayTask $task) => $task->title, $tasks));
        self::assertSame(4, $last?->id);
        self::assertSame([1, 3, 4], self::ids($byDueDate));
    }

    #[Test]
    public function itIteratesOverEveryPage(): void
    {
        $tasks = $this->repository(RelayTask::class)->createQueryBuilder()->orderBy('id', Direction::DESC)->limit(3)->paginate()->getQuery()->toIterable();

        self::assertSame([4, 3, 2, 1], self::ids($tasks));
    }

    #[Test]
    public function itNavigatesOrderedPages(): void
    {
        $firstPage = $this->repository(RelayTask::class)->createQueryBuilder()->orderBy('id', Direction::DESC)->limit(3)->paginate()->getQuery()->getResult();
        self::assertInstanceOf(PaginatedResult::class, $firstPage);

        $secondPage = $firstPage->next();

        self::assertSame([4, 3, 2], self::ids($firstPage->items));
        self::assertSame([1], self::ids($secondPage->items ?? []));
    }

    #[Test]
    public function itSendsTheConditionAndTheOrderingAsTypedVariables(): void
    {
        $resolver = new RootArgumentTypeResolver($this->client, new ArrayAdapter(), 60);

        $tasks = $this->repository(RelayTask::class, $resolver)->findBy(['done' => false], ['id' => Direction::ASC], 2);

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
}
