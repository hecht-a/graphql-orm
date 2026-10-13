<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\PostGraphile;

use GraphqlOrm\Codegen\SchemaPropertyMapper;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Dialect\RelayDialect;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use GraphqlOrm\Schema\SchemaValidator;
use GraphqlOrm\Tests\Integration\IntegrationTestCase;
use GraphqlOrm\Tests\Integration\PostGraphile\Entity\RelayTask;
use GraphqlOrm\Tests\Integration\PostGraphile\Entity\RelayUser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * RelayDialect against the Relay connections of PostGraphile. PostGraphile filters with a `condition` argument, which
 * Relay does not standardize: the find methods are not covered.
 */
#[Group('integration')]
final class RelayTest extends IntegrationTestCase
{
    protected static function endpointVariable(): string
    {
        return 'GRAPHQL_ORM_POSTGRAPHILE_ENDPOINT';
    }

    protected function dialect(): GraphqlQueryDialect
    {
        return new RelayDialect();
    }

    #[Test]
    public function itHydratesTheNodesWithTheirRelationsAndDates(): void
    {
        $tasks = $this->repository(RelayTask::class)->findAll();

        self::assertSame([1, 2, 3, 4], self::ids($tasks));
        self::assertEquals(new \DateTimeImmutable('2026-01-15T10:00:00Z'), $tasks[0]->dueDate);
        self::assertSame(['User A', 'User A', 'User B', null], array_map(fn (RelayTask $task) => $task->user?->name, $tasks));
    }

    #[Test]
    public function itHydratesNestedConnections(): void
    {
        $users = $this->repository(RelayUser::class)->findAll();

        self::assertSame([1, 2], self::ids($users[0]->tasks));
        self::assertSame([3], self::ids($users[1]->tasks));
    }

    #[Test]
    public function itNavigatesBetweenPages(): void
    {
        $firstPage = $this->repository(RelayTask::class)->createQueryBuilder()->limit(3)->paginate()->getQuery()->getResult();
        self::assertInstanceOf(PaginatedResult::class, $firstPage);

        $secondPage = $firstPage->next();
        $backToFirst = $secondPage?->previous();

        self::assertSame([1, 2, 3], self::ids($firstPage->items));
        self::assertSame([4], self::ids($secondPage->items ?? []));
        self::assertFalse($secondPage?->hasNextPage);
        self::assertSame([1, 2, 3], self::ids($backToFirst->items ?? []));
    }

    #[Test]
    public function itPaginatesWithTypedVariables(): void
    {
        $resolver = new RootArgumentTypeResolver($this->client, new ArrayAdapter(), 60);
        $firstPage = $this->repository(RelayTask::class, $resolver)->createQueryBuilder()->limit(2)->paginate()->getQuery()->getResult();
        self::assertInstanceOf(PaginatedResult::class, $firstPage);

        $secondPage = $firstPage->next();

        self::assertSame([3, 4], self::ids($secondPage->items ?? []));
        self::assertSame('Cursor', $resolver->argumentTypes('allTasks')['after'] ?? null);
    }

    #[Test]
    public function itReturnsTheNodesAsArrays(): void
    {
        $rows = $this->repository(RelayUser::class)->createQueryBuilder()->select('name', 'tasks.title')->getQuery()->getArrayResult();

        self::assertEquals(['id' => 1, 'name' => 'User A', 'tasksByUserId' => [
            ['id' => 1, 'title' => 'Write the docs'],
            ['id' => 2, 'title' => 'Review the tests'],
        ]], $rows[0]);
    }

    #[Test]
    public function itValidatesTheMappingAgainstTheSchema(): void
    {
        $schema = $this->schema();

        $violations = (new SchemaValidator(new GraphqlEntityMetadataFactory()))->validate([RelayTask::class, RelayUser::class], $schema->types, $schema->queryType);

        self::assertSame([], $violations);
    }

    #[Test]
    public function itGeneratesEntityPropertiesFromTheSchema(): void
    {
        $schema = $this->schema();

        $properties = [];
        foreach ((new SchemaPropertyMapper())->map($schema, (string) $schema->entityTypeOfRoot('allUsers')) as $property) {
            $properties[$property->name] = [$property->phpType, $property->collection, $property->targetEntity];
        }

        self::assertSame(['int', false, null], $properties['id']);
        self::assertSame(['array', true, 'Task'], $properties['tasksByUserId']);
    }
}
