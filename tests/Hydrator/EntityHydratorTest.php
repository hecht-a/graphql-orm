<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Hydrator;

use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Tests\Fixtures\FakeEntity\Task;
use GraphqlOrm\Tests\Fixtures\FakeEntity\User;
use GraphqlOrm\Tests\Fixtures\FakeRepository\TaskRepository;
use GraphqlOrm\Tests\Fixtures\FakeRepository\UserRepository;
use PHPUnit\Framework\TestCase;

final class EntityHydratorTest extends TestCase
{
    private EntityHydrator $hydrator;

    private GraphqlExecutionContext $context;

    private GraphqlEntityMetadata $taskMetadata;

    private GraphqlEntityMetadata $userMetadata;

    protected function setUp(): void
    {
        $factory = $this->createStub(
            GraphqlEntityMetadataFactory::class
        );

        $this->taskMetadata = new GraphqlEntityMetadata(
            Task::class,
            'tasks',
            TaskRepository::class,
            [
                new GraphqlFieldMetadata(
                    property: 'id',
                    mappedFrom: 'id',
                    isIdentifier: true
                ),
                new GraphqlFieldMetadata(
                    property: 'title',
                    mappedFrom: 'title'
                ),
                new GraphqlFieldMetadata(
                    property: 'user',
                    mappedFrom: 'user',
                    relation: User::class
                ),
            ]
        );

        $this->userMetadata = new GraphqlEntityMetadata(
            User::class,
            'users',
            UserRepository::class,
            [
                new GraphqlFieldMetadata(
                    property: 'id',
                    mappedFrom: 'id',
                    isIdentifier: true
                ),
                new GraphqlFieldMetadata(
                    property: 'name',
                    mappedFrom: 'name'
                ),
                new GraphqlFieldMetadata(
                    property: 'name',
                    mappedFrom: 'name',
                    relation: Task::class,
                    isCollection: true
                ),
            ]
        );

        $factory
            ->method('getMetadata')
            ->willReturnCallback(
                function (string $class) {
                    return match ($class) {
                        Task::class => $this->taskMetadata,

                        User::class => $this->userMetadata,

                        default => throw new \RuntimeException(),
                    };
                }
            );

        $this->hydrator = new EntityHydrator($factory);

        $this->context = new GraphqlExecutionContext();
    }

    public function testHydrateSimpleEntity(): void
    {
        $data = [
            'id' => '1',
            'title' => 'this is a title',
        ];

        $task = $this->hydrator->hydrate(
            $this->taskMetadata,
            $data,
            $this->context
        );

        self::assertInstanceOf(Task::class, $task);

        self::assertSame(1, $task->id);

        self::assertSame(
            'this is a title',
            $task->title
        );
    }

    public function testIdentityMapReturnsSameInstance(): void
    {
        $data = [
            'id' => '1',
            'title' => 'doc',
        ];

        $task1 = $this->hydrator->hydrate($this->taskMetadata, $data, $this->context);
        $task2 = $this->hydrator->hydrate($this->taskMetadata, $data, $this->context);

        self::assertSame($task1, $task2);
    }

    public function testHydrateRelation(): void
    {
        $data = [
            'id' => '1',
            'title' => 'doc',
            'user' => [
                'id' => '1',
                'name' => 'John',
            ],
        ];

        $task = $this->hydrator->hydrate($this->taskMetadata, $data, $this->context);

        self::assertInstanceOf(User::class, $task->user);
        self::assertSame('John', $task->user->name);
    }

    public function testCastStringIdToInt(): void
    {
        $task = $this->hydrator->hydrate(
            $this->taskMetadata,
            [
                'id' => '42',
                'title' => 'doc',
            ],
            $this->context
        );

        self::assertIsInt($task->id);
        self::assertSame(42, $task->id);
    }

    public function testNullableField(): void
    {
        $task = $this->hydrator->hydrate(
            $this->taskMetadata,
            [
                'id' => '1',
                'title' => null,
            ],
            $this->context
        );

        self::assertNull($task->title);
    }

    public function testUnknownFieldIsIgnored(): void
    {
        $task = $this->hydrator->hydrate(
            $this->taskMetadata,
            [
                'id' => '1',
                'title' => 'doc',
                'unknown' => 'value',
            ],
            $this->context
        );

        self::assertSame('doc', $task->title);
    }

    public function testRelationUsesIdentityMap(): void
    {
        $data1 = [
            'id' => '1',
            'title' => 'a',
            'user' => [
                'id' => '10',
                'name' => 'John',
            ],
        ];

        $data2 = [
            'id' => '2',
            'title' => 'b',
            'user' => [
                'id' => '10',
                'name' => 'John',
            ],
        ];

        $task1 = $this->hydrator->hydrate($this->taskMetadata, $data1, $this->context);
        $task2 = $this->hydrator->hydrate($this->taskMetadata, $data2, $this->context);

        self::assertSame($task1->user, $task2->user);
    }

    public function testDeepRecursiveHydration(): void
    {
        $data = [
            'id' => '1',
            'title' => 'root',
            'user' => [
                'id' => '10',
                'name' => 'John',
                'tasks' => [
                    [
                        'id' => '1',
                        'title' => 'root',
                    ],
                    [
                        'id' => '2',
                        'title' => 'second',
                    ],
                ],
            ],
        ];

        $task = $this->hydrator->hydrate($this->taskMetadata, $data, $this->context);

        self::assertSame([], $task->user->tasks);
    }
}
