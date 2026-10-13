<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Repository;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Dialect\HasuraDialect;
use GraphqlOrm\Dialect\RelayDialect;
use GraphqlOrm\Exception\GraphqlRequestException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Test\MockGraphqlServer;
use GraphqlOrm\Tests\Integration\Entity\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AggregateTest extends TestCase
{
    #[Test]
    public function itAggregatesAFieldNamedAfterItsPropertyWithCriteria(): void
    {
        $server = (new MockGraphqlServer())
            ->respond(['tasks_aggregate' => ['aggregate' => ['sum' => ['id' => 10]]]])
            ->respond(['tasks_aggregate' => ['aggregate' => ['avg' => ['id' => 2.5]]]])
            ->respond(['tasks_aggregate' => ['aggregate' => ['min' => ['due_date' => '2026-01-15T10:00:00+00:00']]]])
            ->respond(['tasks_aggregate' => ['aggregate' => ['max' => ['id' => null]]]]);
        $tasks = $this->tasks($server);

        self::assertSame(10, $tasks->sum('id'));
        self::assertSame(2.5, $tasks->avg('id', ['dueDate' => null]));
        self::assertSame('2026-01-15T10:00:00+00:00', $tasks->min('dueDate'));
        self::assertNull($tasks->max('id', ['done' => true]));
        self::assertStringContainsString('tasks_aggregate(where: { due_date: { _is_null: true } }) { aggregate { avg { id } } }', preg_replace('/\s+/', ' ', $server->requests()[1]->query) ?? '');
        self::assertStringContainsString('aggregate { min { due_date } }', preg_replace('/\s+/', ' ', $server->requests()[2]->query) ?? '');
    }

    #[Test]
    public function itNeedsADialectAbleToAggregate(): void
    {
        $tasks = $this->tasks(new MockGraphqlServer(), new RelayDialect());

        $this->expectException(LogicException::class);

        $tasks->sum('id');
    }

    #[Test]
    public function itPointsOutAnUnmappedFieldOfAFailingAggregate(): void
    {
        $tasks = $this->tasks((new MockGraphqlServer())->respondWithErrors([['message' => "field 'estimat' not found"]]));

        try {
            $tasks->sum('estimat', ['titel' => 'A']);
            self::fail('The aggregate must fail.');
        } catch (GraphqlRequestException $exception) {
            self::assertStringContainsString(\sprintf('Field "estimat" is not mapped on %s.', Task::class), $exception->getMessage());
            self::assertStringContainsString(\sprintf('Field "titel" is not mapped on %s: did you mean "title"?', Task::class), $exception->getMessage());
        }
    }

    /**
     * @return GraphqlEntityRepository<Task>
     */
    private function tasks(MockGraphqlServer $server, GraphqlQueryDialect $dialect = new HasuraDialect()): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, new GraphqlClient($server, 'https://graphql.example.com/graphql'), new EntityHydrator($metadataFactory), null, 2, $dialect);

        return new GraphqlEntityRepository($manager, Task::class);
    }
}
