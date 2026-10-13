<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\DataApiBuilder;

use GraphqlOrm\Codegen\SchemaPropertyMapper;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Exception\GraphqlRequestException;
use GraphqlOrm\Exception\NonUniqueResultException;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\Naming\SnakeCaseNamingStrategy;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Schema\GraphqlSchema;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Schema\SchemaValidator;
use GraphqlOrm\Tests\Integration\Entity\BrokenTask;
use GraphqlOrm\Tests\Integration\Entity\SnakeCaseTask;
use GraphqlOrm\Tests\Integration\Entity\Task;
use GraphqlOrm\Tests\Integration\Entity\User;
use GraphqlOrm\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[Group('integration')]
final class DataApiBuilderTest extends IntegrationTestCase
{
    protected static function endpointVariable(): string
    {
        return 'GRAPHQL_ORM_DAB_ENDPOINT';
    }

    protected function dialect(): GraphqlQueryDialect
    {
        return new DataApiBuilderDialect();
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
        $task = $this->tasks()->find(1);

        self::assertInstanceOf(Task::class, $task);
        self::assertSame('Write the docs', $task->title);
        self::assertFalse($task->done);
        self::assertEquals(new \DateTimeImmutable('2026-01-15T10:00:00Z'), $task->dueDate);
        self::assertSame('User A', $task->user?->name);
    }

    #[Test]
    public function itHydratesNestedCollections(): void
    {
        $user = $this->users()->find(1);

        self::assertInstanceOf(User::class, $user);
        self::assertSame([1, 2], array_map(fn (Task $task) => $task->id, $user->tasks));
    }

    #[Test]
    public function itFindsEntitiesByCriteria(): void
    {
        $done = $this->tasks()->findBy(['done' => true]);
        $unassigned = $this->tasks()->findBy(['user_id' => null]);

        self::assertSame([2], array_map(fn (Task $task) => $task->id, $done));
        self::assertSame([4], array_map(fn (Task $task) => $task->id, $unassigned));
    }

    #[Test]
    public function itOrdersAndLimitsResults(): void
    {
        $tasks = $this->tasks()->findBy([], ['title' => Direction::DESC], 2);
        $first = $this->tasks()->findOneBy(['done' => false], ['id' => Direction::DESC]);

        self::assertSame(['Write the docs', 'Review the tests'], array_map(fn (Task $task) => $task->title, $tasks));
        self::assertSame(4, $first?->id);
    }

    #[Test]
    public function itFiltersWithTheQueryBuilder(): void
    {
        $qb = $this->tasks()->createQueryBuilder();

        $tasks = $qb
            ->where($qb->expr()->orX($qb->expr()->contains('title', 'docs'), $qb->expr()->eq('id', 3)))
            ->orderBy('id', Direction::ASC)
            ->getQuery()
            ->getResult();

        self::assertIsArray($tasks);
        self::assertSame([1, 3], array_map(fn (Task $task) => $task->id, $tasks));
    }

    #[Test]
    public function itComparesValuesWithTheQueryBuilder(): void
    {
        $qb = $this->tasks()->createQueryBuilder();
        $expr = $qb->expr();

        $before = $qb
            ->where($expr->andX($expr->isNotNull('due_date'), $expr->lt('due_date', new \DateTimeImmutable('2026-01-20T00:00:00+00:00'))))
            ->getQuery()
            ->getResult();
        $from = $this->tasks()->createQueryBuilder()
            ->where($expr->gte('due_date', new \DateTimeImmutable('2026-01-20T00:00:00+00:00')))
            ->getQuery()
            ->getResult();
        $others = $this->tasks()->createQueryBuilder()
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
        $tasks = $this->tasks()->createQueryBuilder()
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
        $withoutDueDate = $this->tasks()->findBy(['dueDate' => null], ['id' => Direction::ASC]);
        $qb = $this->tasks()->createQueryBuilder();
        $withDueDate = $qb->where($qb->expr()->isNotNull('dueDate'))->orderBy('dueDate', Direction::DESC)->getQuery()->getResult();

        self::assertSame([2, 4], self::ids($withoutDueDate));
        self::assertIsArray($withDueDate);
        self::assertSame([3, 1], self::ids($withDueDate));
        self::assertSame(2, $this->tasks()->count(['dueDate' => null]));
    }

    #[Test]
    public function itExplainsAQueryOnAMisspelledField(): void
    {
        try {
            $this->tasks()->findBy(['titel' => 'A']);
            self::fail('A misspelled field must fail.');
        } catch (GraphqlRequestException $exception) {
            self::assertStringContainsString('titel', $exception->getMessage());
            self::assertStringContainsString(\sprintf('Field "titel" is not mapped on %s: did you mean "title"?', Task::class), $exception->getMessage());
        }
    }

    #[Test]
    public function itReturnsTheSingleResultOfAQuery(): void
    {
        $qb = $this->tasks()->createQueryBuilder();
        $task = $qb->where($qb->expr()->eq('id', 3))->getQuery()->getSingleResult();
        $open = $this->tasks()->createQueryBuilder();

        self::assertSame(3, $task->id);
        $this->expectException(NonUniqueResultException::class);
        $open->where($open->expr()->eq('done', false))->getQuery()->getSingleResult();
    }

    #[Test]
    public function itNamesTheFieldsWithTheSnakeCaseStrategy(): void
    {
        $tasks = $this->repository(SnakeCaseTask::class, namingStrategy: new SnakeCaseNamingStrategy());

        $task = $tasks->find(1);
        $withoutDueDate = $tasks->findBy(['dueDate' => null], ['id' => Direction::ASC]);

        self::assertInstanceOf(SnakeCaseTask::class, $task);
        self::assertSame('2026-01-15', $task->dueDate?->format('Y-m-d'));
        self::assertSame('User A', $task->user?->name);
        self::assertSame([2, 4], self::ids($withoutDueDate));
    }

    #[Test]
    public function itFiltersThroughRelations(): void
    {
        $qb = $this->tasks()->createQueryBuilder();
        $tasks = $qb->where($qb->expr()->orX($qb->expr()->eq('user.name', 'User B'), $qb->expr()->eq('id', 1)))->orderBy('id', Direction::ASC)->getQuery()->getResult();
        $users = $this->users()->createQueryBuilder();
        $withDoneTasks = $users->where($users->expr()->eq('tasks.done', true))->getQuery()->getResult();

        self::assertIsArray($tasks);
        self::assertSame([1, 3], self::ids($tasks));
        self::assertIsArray($withDoneTasks);
        self::assertSame([1], self::ids($withDoneTasks));
    }

    #[Test]
    public function itNavigatesBetweenPages(): void
    {
        $firstPage = $this->tasks()->createQueryBuilder()->orderBy('id', Direction::ASC)->limit(3)->paginate()->getQuery()->getResult();
        self::assertInstanceOf(PaginatedResult::class, $firstPage);

        $secondPage = $firstPage->next();
        $backToFirst = $secondPage?->previous();

        self::assertSame([1, 2, 3], array_map(fn (Task $task) => $task->id, $firstPage->items));
        self::assertSame([4], array_map(fn (Task $task) => $task->id, $secondPage->items ?? []));
        self::assertFalse($secondPage?->hasNextPage);
        self::assertSame([1, 2, 3], array_map(fn (Task $task) => $task->id, $backToFirst->items ?? []));
    }

    #[Test]
    public function itGoesBackToTheFirstPageFromAPaginationStartedAtACursor(): void
    {
        $firstPage = $this->tasks()->createQueryBuilder()->orderBy('id', Direction::ASC)->limit(2)->paginate()->getQuery()->getResult();
        self::assertInstanceOf(PaginatedResult::class, $firstPage);
        self::assertNotNull($firstPage->endCursor);

        $secondPage = $this->tasks()->createQueryBuilder()->orderBy('id', Direction::ASC)->limit(2)->paginate($firstPage->endCursor)->getQuery()->getResult();
        self::assertInstanceOf(PaginatedResult::class, $secondPage);
        $backToFirst = $secondPage->previous();

        self::assertSame([3, 4], self::ids($secondPage->items));
        self::assertSame([1, 2], self::ids($backToFirst->items ?? []));
    }

    #[Test]
    public function itReturnsArrayResultsWithoutEnvelopes(): void
    {
        $rows = $this->users()->createQueryBuilder()->select('name', 'tasks.title')->getQuery()->getArrayResult();

        // Data API Builder does not keep the order of the selected fields.
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

        self::assertSame([1, 3], array_map(fn (Task $task) => $task->id, $tasks));
        self::assertNotNull($resolver->argumentTypes('tasks'));
    }

    #[Test]
    public function itLoadsRelationsLazily(): void
    {
        $task = $this->repository(Task::class, maxDepth: 0, lazyLoading: true)->find(1);

        self::assertSame(1, $task?->user?->id);
        self::assertSame('User A', $task->user->name);
        self::assertSame([1, 2], array_map(fn (Task $userTask) => $userTask->id, $task->user->tasks));
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
    public function itValidatesTheMappingAgainstTheSchema(): void
    {
        $schema = (new SchemaIntrospector($this->client))->introspectSchema();

        $violations = (new SchemaValidator(new GraphqlEntityMetadataFactory()))->validate([Task::class, User::class], $schema['types'], $schema['queryType']);

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
        $introspection = (new SchemaIntrospector($this->client))->introspectSchema();
        $schema = new GraphqlSchema($introspection['types'], $introspection['queryType']);

        $properties = [];
        foreach ((new SchemaPropertyMapper())->map($schema, (string) $schema->entityTypeOfRoot('users')) as $property) {
            $properties[$property->name] = [$property->phpType, $property->nullable, $property->identifier, $property->collection];
        }

        self::assertSame(['int', false, true, false], $properties['id']);
        self::assertSame(['string', false, false, false], $properties['name']);
        self::assertSame(['array', false, false, true], $properties['tasks']);
    }

    /**
     * @return GraphqlEntityRepository<Task>
     */
    private function tasks(): GraphqlEntityRepository
    {
        return $this->repository(Task::class);
    }

    /**
     * @return GraphqlEntityRepository<User>
     */
    private function users(): GraphqlEntityRepository
    {
        return $this->repository(User::class);
    }
}
