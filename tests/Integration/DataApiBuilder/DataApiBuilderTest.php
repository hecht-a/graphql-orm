<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\DataApiBuilder;

use GraphqlOrm\Cache\GraphqlCache;
use GraphqlOrm\Codegen\SchemaPropertyMapper;
use GraphqlOrm\Command\CachedEntities;
use GraphqlOrm\Command\CacheWarmupCommand;
use GraphqlOrm\Command\EntityClassLocator;
use GraphqlOrm\Converter\TypeConverterRegistry;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Exception\GraphqlRequestException;
use GraphqlOrm\Exception\NonUniqueResultException;
use GraphqlOrm\Execution\IdentityMap;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\Naming\SnakeCaseNamingStrategy;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\GraphqlQuery;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Schema\CountableFieldResolver;
use GraphqlOrm\Schema\GraphqlSchema;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Schema\SchemaValidator;
use GraphqlOrm\Tests\Integration\Dto\TaskSummary;
use GraphqlOrm\Tests\Integration\Dto\TaskTitle;
use GraphqlOrm\Tests\Integration\Dto\UserWithTaskTitles;
use GraphqlOrm\Tests\Integration\Entity\BrokenTask;
use GraphqlOrm\Tests\Integration\Entity\CachedTask;
use GraphqlOrm\Tests\Integration\Entity\Label;
use GraphqlOrm\Tests\Integration\Entity\LazyTask;
use GraphqlOrm\Tests\Integration\Entity\SnakeCaseTask;
use GraphqlOrm\Tests\Integration\Entity\Task;
use GraphqlOrm\Tests\Integration\Entity\Title;
use GraphqlOrm\Tests\Integration\Entity\TitleConverter;
use GraphqlOrm\Tests\Integration\Entity\TitledTask;
use GraphqlOrm\Tests\Integration\Entity\User;
use GraphqlOrm\Tests\Integration\Entity\UserWithLazyTasks;
use GraphqlOrm\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[Group('integration')]
final class DataApiBuilderTest extends IntegrationTestCase
{
    protected static function endpointVariable(): string
    {
        return 'GRAPHQL_ORM_DAB_ENDPOINT';
    }

    protected function dialect(): GraphqlQueryDialect
    {
        return new DataApiBuilderDialect(new CountableFieldResolver($this->client, new ArrayAdapter(), 3600));
    }

    #[Test]
    public function itAnswersAPageFromTheResultCache(): void
    {
        $cache = new GraphqlCache(new ArrayAdapter());
        $query = fn (?GraphqlOrmDataCollector $collector = null) => $this->repository(Task::class, collector: $collector, cache: $cache)
            ->createQueryBuilder()->paginate()->limit(2)->getQuery()->enableResultCache(lifetime: 60);
        $first = $query()->getResult();
        $collector = new GraphqlOrmDataCollector();

        $second = $query($collector)->getResult();

        self::assertInstanceOf(PaginatedResult::class, $second);
        self::assertInstanceOf(PaginatedResult::class, $first);
        self::assertSame(self::ids($first->items), self::ids($second->items));
        self::assertSame(['hit'], array_column($collector->getQueries(), 'result_cache'));
    }

    #[Test]
    public function itFindsAnEntityOfTheSecondLevelCacheAndLoadsItsRelationOnFirstAccess(): void
    {
        $cache = new GraphqlCache(entities: new ArrayAdapter(), defaultLifetime: 60);
        $this->repository(CachedTask::class, cache: $cache)->findAll();
        $collector = new GraphqlOrmDataCollector();

        $task = $this->repository(CachedTask::class, collector: $collector, cache: $cache)->find(1);

        self::assertInstanceOf(CachedTask::class, $task);
        self::assertSame('Write the docs', $task->title);
        self::assertEquals(new \DateTimeImmutable('2026-01-15T10:00:00Z'), $task->dueDate);
        self::assertSame(0, $collector->getQueryCount());
        self::assertSame('User A', $task->user?->name);
        self::assertSame(1, $collector->getQueryCount());
    }

    #[Test]
    public function itCountsEntities(): void
    {
        self::assertSame(4, $this->repository(Task::class)->count());
        self::assertSame(3, $this->repository(Task::class)->count(['done' => false]));
        self::assertSame(1, $this->repository(Task::class)->count(['done' => true]));
    }

    #[Test]
    public function itCountsAnEntityWithATextIdentifierOnAFieldNeverNull(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $labels = $this->repository(Label::class, collector: $collector);

        self::assertSame(3, $labels->count());
        self::assertSame(1, $labels->count(['code' => 'bug']));
        $graphql = $collector->getQueries()[0]['graphql'] ?? null;
        self::assertIsString($graphql);
        self::assertStringContainsString('count(field: position)', $graphql);
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
        $tasks = $this->tasks()->findBy([], ['title' => Direction::Desc], 2);
        $first = $this->tasks()->findOneBy(['done' => false], ['id' => Direction::Desc]);

        self::assertSame(['Write the docs', 'Review the tests'], array_map(fn (Task $task) => $task->title, $tasks));
        self::assertSame(4, $first?->id);
    }

    #[Test]
    public function itFiltersWithTheQueryBuilder(): void
    {
        $qb = $this->tasks()->createQueryBuilder();

        $tasks = $qb
            ->where($qb->expr()->orX($qb->expr()->contains('title', 'docs'), $qb->expr()->eq('id', 3)))
            ->orderBy('id', Direction::Asc)
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
            ->orderBy('id', Direction::Asc)
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
            ->orderBy('id', Direction::Asc)
            ->limit(3)
            ->paginate()
            ->getQuery()
            ->toIterable();

        self::assertSame([1, 2, 3, 4], self::ids($tasks));
    }

    #[Test]
    public function itAcceptsThePropertyNamesOfTheEntity(): void
    {
        $withoutDueDate = $this->tasks()->findBy(['dueDate' => null], ['id' => Direction::Asc]);
        $qb = $this->tasks()->createQueryBuilder();
        $withDueDate = $qb->where($qb->expr()->isNotNull('dueDate'))->orderBy('dueDate', Direction::Desc)->getQuery()->getResult();

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
        $withoutDueDate = $tasks->findBy(['dueDate' => null], ['id' => Direction::Asc]);

        self::assertInstanceOf(SnakeCaseTask::class, $task);
        self::assertSame('2026-01-15', $task->dueDate?->format('Y-m-d'));
        self::assertSame('User A', $task->user?->name);
        self::assertSame([2, 4], self::ids($withoutDueDate));
    }

    #[Test]
    public function itFiltersThroughRelations(): void
    {
        $qb = $this->tasks()->createQueryBuilder();
        $tasks = $qb->where($qb->expr()->orX($qb->expr()->eq('user.name', 'User B'), $qb->expr()->eq('id', 1)))->orderBy('id', Direction::Asc)->getQuery()->getResult();
        $users = $this->users()->createQueryBuilder();
        $withDoneTasks = $users->where($users->expr()->eq('tasks.done', true))->getQuery()->getResult();

        self::assertIsArray($tasks);
        self::assertSame([1, 3], self::ids($tasks));
        self::assertIsArray($withDoneTasks);
        self::assertSame([1], self::ids($withDoneTasks));
    }

    #[Test]
    public function itAggregatesAField(): void
    {
        $tasks = $this->tasks();

        self::assertSame(10, $tasks->sum('id'));
        self::assertEqualsWithDelta(8 / 3, $tasks->avg('id', ['done' => false]), 0.0001);
        self::assertSame(1, $tasks->min('id'));
        self::assertSame(2, $tasks->max('id', ['done' => true]));
        self::assertNull($tasks->max('id', ['title' => 'No such task']));
    }

    #[Test]
    public function itRunsQueriesConcurrently(): void
    {
        [$tasks, $users] = GraphqlQuery::concurrently(
            $this->tasks()->createQueryBuilder()->orderBy('id', Direction::Asc)->getQuery(),
            $this->users()->createQueryBuilder()->orderBy('id', Direction::Asc)->getQuery(),
        );

        self::assertSame([1, 2, 3, 4], self::ids($tasks instanceof PaginatedResult ? $tasks->items : $tasks));
        self::assertSame([1, 2], self::ids($users instanceof PaginatedResult ? $users->items : $users));
    }

    #[Test]
    public function itMatchesAListOfValues(): void
    {
        self::assertSame([1, 3], self::ids($this->tasks()->findBy(['id' => [1, 3]], ['id' => Direction::Asc])));
        self::assertSame([], $this->tasks()->findBy(['id' => []]));
        self::assertSame(2, $this->tasks()->count(['id' => [1, 3, 99]]));
    }

    #[Test]
    public function itAcceptsAnEmptyOrdering(): void
    {
        self::assertCount(2, $this->tasks()->findBy([], [], 2));
    }

    #[Test]
    public function itServesTheKnownEntitiesFromASharedIdentityMap(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $identityMap = new IdentityMap();
        $tasks = $this->repository(Task::class, collector: $collector, identityMap: $identityMap);
        $users = $this->repository(User::class, collector: $collector, identityMap: $identityMap);

        $all = $tasks->findAll();
        $task = $tasks->find(1);
        $user = $users->find(1);

        self::assertSame($all[0], $task);
        self::assertSame($task->user, $user);
        // The user came in full with the tasks (max_depth 2): neither find() sends a query.
        self::assertSame(1, $collector->getQueryCount());
    }

    #[Test]
    public function itLoadsTheLazyRelationsOnFirstAccess(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $tasks = $this->repository(LazyTask::class, collector: $collector)->findBy(['id' => [1, 3]], ['id' => Direction::Asc]);
        $users = $this->repository(UserWithLazyTasks::class, collector: $collector)->findBy(['id' => 1]);

        self::assertSame(2, $collector->getQueryCount());
        self::assertSame(['User A', 'User B'], [$tasks[0]->user?->name, $tasks[1]->user?->name]);
        self::assertSame(['Write the docs', 'Review the tests'], array_map(fn (Task $task) => $task->title, $users[0]->tasks));
        // One query for both users, one for both tasks: the ghosts of a response are loaded together.
        self::assertSame(4, $collector->getQueryCount());
    }

    #[Test]
    public function itSendsQueriesInOneDocument(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $manager = $this->manager($collector);
        $tasks = (new GraphqlEntityRepository($manager, Task::class))->createQueryBuilder();

        [$open, $users] = GraphqlQuery::batch(
            $tasks->where($tasks->expr()->eq('done', false))->orderBy('id', Direction::Asc)->getQuery(),
            (new GraphqlEntityRepository($manager, User::class))->createQueryBuilder()->orderBy('id', Direction::Asc)->getQuery(),
        );

        self::assertSame(1, $collector->getQueryCount());
        self::assertIsArray($open);
        self::assertSame([1, 3, 4], self::ids($open));
        self::assertIsArray($users);
        self::assertSame([1, 2], self::ids($users));
    }

    #[Test]
    public function itNavigatesBetweenPages(): void
    {
        $firstPage = $this->tasks()->createQueryBuilder()->orderBy('id', Direction::Asc)->limit(3)->paginate()->getQuery()->getResult();
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
        $firstPage = $this->tasks()->createQueryBuilder()->orderBy('id', Direction::Asc)->limit(2)->paginate()->getQuery()->getResult();
        self::assertInstanceOf(PaginatedResult::class, $firstPage);
        self::assertNotNull($firstPage->endCursor);

        $secondPage = $this->tasks()->createQueryBuilder()->orderBy('id', Direction::Asc)->limit(2)->paginate($firstPage->endCursor)->getQuery()->getResult();
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

        $tasks = $this->repository(Task::class, $resolver)->findBy(['done' => false], ['id' => Direction::Asc], 2);
        $listed = $this->repository(Task::class, $resolver)->findBy(['id' => [2, 4]], ['id' => Direction::Asc]);

        self::assertSame([1, 3], array_map(fn (Task $task) => $task->id, $tasks));
        self::assertSame([2, 4], self::ids($listed));
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
        $tasks = $this->repository(Task::class, maxDepth: 0, lazyLoading: true, collector: $collector)->findBy([], ['id' => Direction::Asc]);

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

    #[Test]
    public function itLoadsTheRelationsNamedByWithBeyondTheMaxDepth(): void
    {
        $qb = $this->repository(Task::class, maxDepth: 1)->createQueryBuilder();

        $task = $qb->with('user.tasks')->where($qb->expr()->eq('id', 1))->getQuery()->getOneOrNullResult();

        self::assertInstanceOf(Task::class, $task);
        $titles = array_map(static fn (Task $task) => $task->title, $task->user->tasks ?? []);
        sort($titles);
        self::assertSame(['Review the tests', 'Write the docs'], $titles);
    }

    #[Test]
    public function itLoadsALazyRelationNamedByWithInTheQuery(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $qb = $this->repository(LazyTask::class, collector: $collector)->createQueryBuilder();

        $task = $qb->with('user')->where($qb->expr()->eq('id', 3))->getQuery()->getOneOrNullResult();

        self::assertSame('User B', $task?->user?->name);
        self::assertCount(1, $collector->getQueries());
    }

    #[Test]
    public function itHydratesDtosFromTheFieldsTheyMap(): void
    {
        $summaries = $this->tasks()->createQueryBuilder()
            ->orderBy('id', Direction::Asc)
            ->hydrateAs(TaskSummary::class)
            ->getQuery()
            ->getResult();

        self::assertIsArray($summaries);
        self::assertEquals(new TaskSummary(1, 'Write the docs', new \DateTimeImmutable('2026-01-15T10:00:00Z'), 'User A'), $summaries[0]);
        self::assertEquals(new TaskSummary(4, 'Plan the next release', null, null), $summaries[3]);
    }

    #[Test]
    public function itHydratesADtoWithACollectionOfDtos(): void
    {
        $user = $this->users()->createQueryBuilder()
            ->where($this->users()->createQueryBuilder()->expr()->eq('id', 1))
            ->hydrateAs(UserWithTaskTitles::class)
            ->getQuery()
            ->getOneOrNullResult();

        self::assertInstanceOf(UserWithTaskTitles::class, $user);
        self::assertSame('User A', $user->name);
        self::assertEqualsCanonicalizing([new TaskTitle('Write the docs', false), new TaskTitle('Review the tests', true)], $user->tasks);
    }

    #[Test]
    public function itConvertsAValueObjectFromAndToTheApi(): void
    {
        $qb = $this->repository(TitledTask::class, typeConverters: new TypeConverterRegistry([new TitleConverter()]))->createQueryBuilder();

        $task = $qb->where($qb->expr()->eq('title', new Title('Release the bundle')))->getQuery()->getOneOrNullResult();

        self::assertInstanceOf(TitledTask::class, $task);
        self::assertSame(3, $task->id);
        self::assertEquals(new Title('Release the bundle'), $task->title);
    }

    #[Test]
    public function itWarmsUpTheSecondLevelCachePageByPage(): void
    {
        $cache = new GraphqlCache(entities: new ArrayAdapter(), defaultLifetime: 60);
        $collector = new GraphqlOrmDataCollector();
        $managers = GraphqlManagerRegistry::forManager($this->manager($collector, cache: $cache));
        $command = new CacheWarmupCommand($managers, new CachedEntities($managers, new EntityClassLocator(\dirname(__DIR__) . '/Entity', 'GraphqlOrm\\Tests\\Integration\\Entity'), []));
        $tester = new CommandTester((new Command('graphqlorm:cache:warmup'))->setCode($command));

        self::assertSame(Command::SUCCESS, $tester->execute(['entity' => 'CachedTask', '--batch-size' => '2']));
        self::assertCount(2, $collector->getQueries());
        foreach ([1, 2, 3, 4] as $id) {
            self::assertTrue($cache->containsEntity(CachedTask::class, $id));
        }
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
