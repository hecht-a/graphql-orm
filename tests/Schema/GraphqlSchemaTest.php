<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Schema;

use GraphqlOrm\Schema\GraphqlSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GraphqlSchemaTest extends TestCase
{
    private const TASK = ['kind' => 'OBJECT', 'fields' => ['id' => ['kind' => 'SCALAR', 'name' => 'Int']]];

    #[Test]
    public function itResolvesTheTypeReturnedByTheRootField(): void
    {
        $schema = new GraphqlSchema([
            'Query' => ['kind' => 'OBJECT', 'fields' => ['tasks' => ['kind' => 'OBJECT', 'name' => 'Task']]],
            'Task' => self::TASK,
        ]);

        self::assertSame('Task', $schema->entityTypeOfRoot('tasks'));
    }

    #[Test]
    public function itUnwrapsAConnectionReturnedByTheRootField(): void
    {
        $schema = new GraphqlSchema([
            'RootQuery' => ['kind' => 'OBJECT', 'fields' => ['tasks' => ['kind' => 'OBJECT', 'name' => 'TaskConnection']]],
            'TaskConnection' => ['kind' => 'OBJECT', 'fields' => ['items' => ['kind' => 'OBJECT', 'name' => 'Task']]],
            'Task' => self::TASK,
        ], 'RootQuery');

        self::assertSame('Task', $schema->entityTypeOfRoot('tasks'));
    }

    #[Test]
    public function itGuessesTheTypeFromTheRootNameWhenTheRootFieldIsUnknown(): void
    {
        $schema = new GraphqlSchema(['Task' => self::TASK]);

        self::assertSame('Task', $schema->entityTypeOfRoot('tasks'));
    }

    #[Test]
    public function itReturnsNullWhenNoTypeMatches(): void
    {
        $schema = new GraphqlSchema(['Task' => self::TASK]);

        self::assertNull($schema->entityTypeOfRoot('invoices'));
    }
}
