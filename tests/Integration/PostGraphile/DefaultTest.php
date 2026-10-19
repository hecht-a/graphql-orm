<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\PostGraphile;

use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Schema\SchemaValidator;
use GraphqlOrm\Tests\Integration\IntegrationTestCase;
use GraphqlOrm\Tests\Integration\PostGraphile\Entity\ListTask;
use GraphqlOrm\Tests\Integration\PostGraphile\Entity\ListUser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * DefaultDialect against the simple collections (plain lists) of PostGraphile. PostGraphile filters with a `condition`
 * argument, which standard GraphQL does not define: the find methods are not covered.
 */
#[Group('integration')]
final class DefaultTest extends IntegrationTestCase
{
    protected static function taskClass(): string
    {
        return ListTask::class;
    }

    protected static function endpointVariable(): string
    {
        return 'GRAPHQL_ORM_POSTGRAPHILE_ENDPOINT';
    }

    protected function dialect(): GraphqlQueryDialect
    {
        return new DefaultDialect();
    }

    #[Test]
    public function itHydratesPlainListsWithTheirRelationsAndDates(): void
    {
        $tasks = $this->repository(ListTask::class)->findAll();

        self::assertSame([1, 2, 3, 4], self::ids($tasks));
        self::assertEquals(new \DateTimeImmutable('2026-01-15T10:00:00Z'), $tasks[0]->dueDate);
        self::assertSame(['User A', 'User A', 'User B', null], array_map(fn (ListTask $task) => $task->user?->name, $tasks));
    }

    #[Test]
    public function itHydratesNestedLists(): void
    {
        $users = $this->repository(ListUser::class)->findAll();

        self::assertSame([1, 2], self::ids($users[0]->tasks));
    }

    #[Test]
    public function itLimitsWithFirst(): void
    {
        $tasks = $this->repository(ListTask::class)->createQueryBuilder()->limit(2)->getQuery()->getResult();
        $first = $this->repository(ListTask::class)->createQueryBuilder()->getQuery()->getOneOrNullResult();

        self::assertIsArray($tasks);
        self::assertSame([1, 2], self::ids($tasks));
        self::assertSame(1, $first?->id);
    }

    #[Test]
    public function itReturnsArrayResults(): void
    {
        $rows = $this->repository(ListUser::class)->createQueryBuilder()->select('name', 'tasks.title')->getQuery()->getArrayResult();

        self::assertEquals(['id' => 1, 'name' => 'User A', 'tasksByUserIdList' => [
            ['id' => 1, 'title' => 'Write the docs'],
            ['id' => 2, 'title' => 'Review the tests'],
        ]], $rows[0]);
    }

    #[Test]
    public function itValidatesTheMappingAgainstTheSchema(): void
    {
        $schema = $this->schema();

        $violations = (new SchemaValidator(new GraphqlEntityMetadataFactory()))->validate([ListTask::class, ListUser::class], $schema->types, $schema->queryType);

        self::assertSame([], $violations);
    }
}
