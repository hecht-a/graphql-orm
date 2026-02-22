<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Query\GraphqlQueryStringBuilder;
use GraphqlOrm\Tests\Fixtures\FakeEntity\Task;
use GraphqlOrm\Tests\Fixtures\FakeEntity\User;
use GraphqlOrm\Tests\Fixtures\FakeRepository\TaskRepository;
use GraphqlOrm\Tests\Fixtures\FakeRepository\UserRepository;
use PHPUnit\Framework\TestCase;

final class GraphqlQueryStringBuilderTest extends TestCase
{
    public function testBuildWithArgumentsFormatting(): void
    {
        $manager = $this->createManager();

        $query = (new GraphqlQueryStringBuilder($manager))
            ->root('task')
            ->entity(Task::class)
            ->arguments([
                'id' => 1,
                'active' => true,
                'status' => 'OPEN',
                'tags' => ['a', 'b'],
                'nullable' => null,
            ])
            ->build();

        self::assertStringContainsString('task(id: 1, active: true, status: "OPEN", tags: ["a", "b"], nullable: null)', $query);
    }

    public function testManualSelectSimpleField(): void
    {
        $manager = $this->createManager();

        $query = (new GraphqlQueryStringBuilder($manager))
            ->root('task')
            ->entity(Task::class)
            ->fields(['title'], true)
            ->build();

        self::assertSame(
            <<<GRAPHQL
query {
  task {
    id
    title
  }
}
GRAPHQL,
            $query
        );
    }

    public function testManualSelectNestedRelation(): void
    {
        $manager = $this->createManager();

        $query = (new GraphqlQueryStringBuilder($manager))
            ->root('task')
            ->entity(Task::class)
            ->fields([
                'title',
                'user.name',
            ], true)
            ->build();

        self::assertSame(
            <<<GRAPHQL
query {
  task {
    id
    title
    user {
      name
      id
    }
  }
}
GRAPHQL,
            $query
        );
    }

    public function testManualSelectExplicitRelationLoadsAllFields(): void
    {
        $manager = $this->createManager();

        $query = (new GraphqlQueryStringBuilder($manager))
            ->root('task')
            ->entity(Task::class)
            ->fields(['user'], true)
            ->build();

        self::assertSame(
            <<<GRAPHQL
query {
  task {
    id
    user {
      id
      name
    }
  }
}
GRAPHQL,
            $query
        );
    }

    public function testManualSelectRelationFallbackWhenNoChildren(): void
    {
        $metadataFactory = $this->createCycleMetadataFactory();

        $manager = new GraphqlManager(
            $metadataFactory,
            $this->createStub(GraphqlClient::class),
            $this->createStub(EntityHydrator::class),
            $this->createStub(GraphqlOrmDataCollector::class),
            3
        );

        $query = (new GraphqlQueryStringBuilder($manager))
            ->root('user')
            ->entity(User::class)
            ->fields(['manager'], true)
            ->build();

        self::assertStringContainsString(
            <<<GRAPHQL
      manager {
        id
      }
GRAPHQL,
            $query
        );
    }

    public function testCycleDetectionFallback(): void
    {
        $metadataFactory = $this->createCycleMetadataFactory();

        $manager = new GraphqlManager(
            $metadataFactory,
            $this->createStub(GraphqlClient::class),
            $this->createStub(EntityHydrator::class),
            $this->createStub(GraphqlOrmDataCollector::class),
            3
        );

        $query = (new GraphqlQueryStringBuilder($manager))
            ->root('user')
            ->entity(User::class)
            ->build();

        self::assertStringContainsString(
            <<<GRAPHQL
    manager {
      id
    }
GRAPHQL,
            $query
        );
    }

    public function testUnknownFieldFallsBackToRawName(): void
    {
        $manager = $this->createManager();

        $query = (new GraphqlQueryStringBuilder($manager))
            ->root('task')
            ->entity(Task::class)
            ->fields(['customGraphqlField'], true)
            ->build();

        self::assertStringContainsString('customGraphqlField', $query);
    }

    public function testFormatValueThrowsOnObject(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $manager = $this->createManager();

        (new GraphqlQueryStringBuilder($manager))
            ->root('task')
            ->entity(Task::class)
            ->arguments([
                'invalid' => new \stdClass(),
            ])
            ->build();
    }

    private function createManager(): GraphqlManager
    {
        $metadataFactory = $this->createStub(GraphqlEntityMetadataFactory::class);

        $taskMetadata = new GraphqlEntityMetadata(
            Task::class,
            'tasks',
            TaskRepository::class,
            [
                $this->field('id', 'id'),
                $this->field('title', 'title'),
                $this->field('user', 'user', User::class),
            ],
            $this->field('id', 'id')
        );

        $userMetadata = new GraphqlEntityMetadata(
            User::class,
            'users',
            UserRepository::class,
            [
                $this->field('id', 'id'),
                $this->field('name', 'name'),
            ],
            $this->field('id', 'id')
        );

        $metadataFactory
            ->method('getMetadata')
            ->willReturnMap([
                [Task::class, $taskMetadata],
                [User::class, $userMetadata],
            ]);

        return new GraphqlManager(
            $metadataFactory,
            $this->createStub(GraphqlClient::class),
            $this->createStub(EntityHydrator::class),
            $this->createStub(GraphqlOrmDataCollector::class),
            3
        );
    }

    private function createCycleMetadataFactory(): GraphqlEntityMetadataFactory
    {
        $metadataFactory = $this->createStub(GraphqlEntityMetadataFactory::class);

        $userMetadata = new GraphqlEntityMetadata(
            User::class,
            'users',
            UserRepository::class,
            [
                $this->field('id', 'id'),
                $this->field('manager', 'manager', User::class),
            ],
            $this->field('id', 'id')
        );

        $metadataFactory
            ->method('getMetadata')
            ->willReturnMap([
                [User::class, $userMetadata],
            ]);

        return $metadataFactory;
    }

    private function field(string $property, string $mappedFrom, ?string $relation = null): GraphqlFieldMetadata
    {
        return new GraphqlFieldMetadata($property, $mappedFrom, $relation);
    }
}
