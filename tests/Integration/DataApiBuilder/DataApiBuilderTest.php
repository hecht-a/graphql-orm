<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\DataApiBuilder;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Codegen\SchemaPropertyMapper;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Schema\GraphqlSchema;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Schema\SchemaValidator;
use GraphqlOrm\Tests\Integration\DataApiBuilder\Entity\Task;
use GraphqlOrm\Tests\Integration\DataApiBuilder\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Runs the bundle against a real Data API Builder (tests/Integration/DataApiBuilder/compose.yaml), whose endpoint is
 * given by the GRAPHQL_ORM_DAB_ENDPOINT environment variable.
 */
#[Group('integration')]
final class DataApiBuilderTest extends TestCase
{
    private const ENDPOINT_VARIABLE = 'GRAPHQL_ORM_DAB_ENDPOINT';

    private GraphqlClient $client;

    protected function setUp(): void
    {
        $endpoint = getenv(self::ENDPOINT_VARIABLE);

        if (!\is_string($endpoint) || $endpoint === '') {
            self::markTestSkipped(\sprintf('Set %s to run the Data API Builder integration tests.', self::ENDPOINT_VARIABLE));
        }

        $this->client = new GraphqlClient(HttpClient::create(), $endpoint);
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
    public function itValidatesTheMappingAgainstTheSchema(): void
    {
        $schema = (new SchemaIntrospector($this->client))->introspectSchema();

        $violations = (new SchemaValidator(new GraphqlEntityMetadataFactory()))->validate([Task::class, User::class], $schema['types'], $schema['queryType']);

        self::assertSame([], $violations);
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

    /**
     * @template T of object
     *
     * @param class-string<T> $entityClass
     *
     * @return GraphqlEntityRepository<T>
     */
    private function repository(string $entityClass, ?RootArgumentTypeResolver $argumentTypeResolver = null, int $maxDepth = 2, bool $lazyLoading = false): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager(
            $metadataFactory,
            $this->client,
            new EntityHydrator($metadataFactory),
            null,
            $maxDepth,
            new DataApiBuilderDialect(),
            argumentTypeResolver: $argumentTypeResolver,
            lazyLoading: $lazyLoading,
        );

        return new GraphqlEntityRepository($manager, $entityClass);
    }
}
