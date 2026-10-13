<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Hasura;

use GraphqlOrm\Codegen\SchemaPropertyMapper;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Dialect\HasuraDialect;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use GraphqlOrm\Schema\SchemaValidator;
use GraphqlOrm\Tests\Integration\Entity\BrokenTask;
use GraphqlOrm\Tests\Integration\Entity\Task;
use GraphqlOrm\Tests\Integration\Entity\User;
use GraphqlOrm\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[Group('integration')]
final class HasuraTest extends IntegrationTestCase
{
    protected static function endpointVariable(): string
    {
        return 'GRAPHQL_ORM_HASURA_ENDPOINT';
    }

    protected function dialect(): GraphqlQueryDialect
    {
        return new HasuraDialect();
    }

    #[Test]
    public function itComparesValuesWithTheQueryBuilder(): void
    {
        $qb = $this->repository(Task::class)->createQueryBuilder();
        $expr = $qb->expr();

        $before = $qb
            ->where($expr->andX($expr->isNotNull('due_date'), $expr->lt('due_date', new \DateTimeImmutable('2026-01-20T00:00:00+00:00'))))
            ->getQuery()
            ->getResult();
        $from = $this->repository(Task::class)->createQueryBuilder()
            ->where($expr->gte('due_date', new \DateTimeImmutable('2026-01-20T00:00:00+00:00')))
            ->getQuery()
            ->getResult();
        $others = $this->repository(Task::class)->createQueryBuilder()
            ->where($expr->notIn('id', [1, 2]))
            ->orderBy('id', Direction::ASC)
            ->getQuery()
            ->getResult();

        self::assertIsArray($before);
        self::assertSame([1], self::ids($before));
        self::assertIsArray($from);
        self::assertSame([3], self::ids($from));
        self::assertIsArray($others);
        self::assertSame([3, 4], self::ids($others));
    }

    #[Test]
    public function itIteratesOverEveryPage(): void
    {
        $tasks = $this->repository(Task::class)->createQueryBuilder()
            ->orderBy('id', Direction::ASC)
            ->limit(3)
            ->paginate()
            ->getQuery()
            ->toIterable();

        self::assertSame([1, 2, 3, 4], self::ids($tasks));
    }

    #[Test]
    public function itAcceptsThePropertyNamesOfTheEntity(): void
    {
        $withoutDueDate = $this->repository(Task::class)->findBy(['dueDate' => null], ['id' => Direction::ASC]);
        $qb = $this->repository(Task::class)->createQueryBuilder();
        $withDueDate = $qb->where($qb->expr()->isNotNull('dueDate'))->orderBy('dueDate', Direction::DESC)->getQuery()->getResult();

        self::assertSame([2, 4], self::ids($withoutDueDate));
        self::assertIsArray($withDueDate);
        self::assertSame([3, 1], self::ids($withDueDate));
        self::assertSame(2, $this->repository(Task::class)->count(['dueDate' => null]));
    }

    #[Test]
    public function itCountsEntities(): void
    {
        self::assertSame(4, $this->repository(Task::class)->count());
        self::assertSame(3, $this->repository(Task::class)->count(['done' => false]));
        self::assertSame(1, $this->repository(Task::class)->count(['done' => true]));
    }

    #[Test]
    public function itHydratesEntitiesWithTheirRelationsAndDates(): void
    {
        $task = $this->repository(Task::class)->find(1);

        self::assertInstanceOf(Task::class, $task);
        self::assertSame('Write the docs', $task->title);
        self::assertEquals(new \DateTimeImmutable('2026-01-15T10:00:00Z'), $task->dueDate);
        self::assertSame('User A', $task->user?->name);
    }

    #[Test]
    public function itHydratesNestedCollections(): void
    {
        $user = $this->repository(User::class)->find(1);

        self::assertSame([1, 2], self::ids($user->tasks ?? []));
    }

    #[Test]
    public function itFindsEntitiesByCriteria(): void
    {
        self::assertSame([2], self::ids($this->repository(Task::class)->findBy(['done' => true])));
        self::assertSame([4], self::ids($this->repository(Task::class)->findBy(['user_id' => null])));
    }

    #[Test]
    public function itOrdersAndLimitsResults(): void
    {
        $tasks = $this->repository(Task::class)->findBy([], ['title' => Direction::DESC], 2);
        $last = $this->repository(Task::class)->findOneBy(['done' => false], ['id' => Direction::DESC]);

        self::assertSame(['Write the docs', 'Review the tests'], array_map(fn (Task $task) => $task->title, $tasks));
        self::assertSame(4, $last?->id);
    }

    #[Test]
    public function itTranslatesTheQueryBuilderFilters(): void
    {
        $qb = $this->repository(Task::class)->createQueryBuilder();

        $tasks = $qb
            ->where($qb->expr()->orX($qb->expr()->contains('title', 'docs'), $qb->expr()->in('id', [3, 4])))
            ->orderBy('id', Direction::ASC)
            ->getQuery()
            ->getResult();

        self::assertIsArray($tasks);
        self::assertSame([1, 3, 4], self::ids($tasks));
    }

    #[Test]
    public function itMatchesLikeWildcardsLiterally(): void
    {
        $qb = $this->repository(Task::class)->createQueryBuilder();

        $tasks = $qb->where($qb->expr()->contains('title', '_'))->getQuery()->getResult();

        self::assertSame([], $tasks);
    }

    #[Test]
    public function itReturnsArrayResults(): void
    {
        $rows = $this->repository(User::class)->createQueryBuilder()->select('name', 'tasks.title')->orderBy('id', Direction::ASC)->getQuery()->getArrayResult();

        self::assertEquals(['id' => 1, 'name' => 'User A', 'tasks' => [
            ['id' => 1, 'title' => 'Write the docs'],
            ['id' => 2, 'title' => 'Review the tests'],
        ]], $rows[0]);
    }

    #[Test]
    public function itSendsArgumentsAsTypedVariables(): void
    {
        $resolver = new RootArgumentTypeResolver($this->client, new ArrayAdapter(), 60);

        $tasks = $this->repository(Task::class, $resolver)->findBy(['done' => false], ['id' => Direction::ASC], 2);

        self::assertSame([1, 3], self::ids($tasks));
        self::assertNotNull($resolver->argumentTypes('tasks'));
    }

    #[Test]
    public function itLoadsTheLazyRelationsOfAResponseWithOneQuery(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $tasks = $this->repository(Task::class, maxDepth: 0, lazyLoading: true, collector: $collector)->findBy([], ['id' => Direction::ASC]);

        $names = array_map(fn (Task $task) => $task->user?->name, $tasks);

        self::assertSame(['User A', 'User A', 'User B', null], $names);
        self::assertSame(2, $collector->getQueryCount());
    }

    #[Test]
    public function itNavigatesPagesWithAnOffset(): void
    {
        $firstPage = $this->repository(Task::class)->createQueryBuilder()->orderBy('id', Direction::ASC)->limit(3)->paginate()->getQuery()->getResult();
        self::assertInstanceOf(PaginatedResult::class, $firstPage);

        $secondPage = $firstPage->next();
        $backToFirst = $secondPage?->previous();

        self::assertSame([1, 2, 3], self::ids($firstPage->items));
        self::assertTrue($firstPage->hasNextPage);
        self::assertSame([4], self::ids($secondPage->items ?? []));
        self::assertFalse($secondPage?->hasNextPage);
        self::assertSame([1, 2, 3], self::ids($backToFirst->items ?? []));
    }

    #[Test]
    public function itValidatesTheMappingAgainstTheSchema(): void
    {
        $schema = $this->schema();

        $violations = (new SchemaValidator(new GraphqlEntityMetadataFactory()))->validate([Task::class, User::class], $schema->types, $schema->queryType);

        self::assertSame([], $violations);
    }

    #[Test]
    public function itReportsAWrongMappingFromTheRealSchema(): void
    {
        $schema = $this->schema();

        $violations = (new SchemaValidator(new GraphqlEntityMetadataFactory()))->validate([BrokenTask::class], $schema->types, $schema->queryType);

        self::assertSame([
            \sprintf('[%s] Field "due_date" is nullable in the schema but property "dueDate" does not accept null: declare it as "?DateTimeImmutable".', BrokenTask::class),
            \sprintf('[%s] Field "user" is a collection but returns a single object in the schema: map it on a single relation.', BrokenTask::class),
        ], $violations);
    }

    #[Test]
    public function itGeneratesEntityPropertiesFromTheSchema(): void
    {
        $schema = $this->schema();

        $properties = [];
        foreach ((new SchemaPropertyMapper())->map($schema, (string) $schema->entityTypeOfRoot('tasks')) as $property) {
            $properties[$property->name] = [$property->phpType, $property->nullable, $property->identifier, $property->collection];
        }

        self::assertSame(['int', false, true, false], $properties['id']);
        self::assertSame(['\DateTimeImmutable', true, false, false], $properties['due_date']);
        self::assertSame(['User', true, false, false], $properties['user']);
    }
}
