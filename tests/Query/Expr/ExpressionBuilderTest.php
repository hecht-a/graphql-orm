<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query\Expr;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Query\Expr\ExpressionBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExpressionBuilderTest extends TestCase
{
    private ExpressionBuilder $expr;

    protected function setUp(): void
    {
        $this->expr = new ExpressionBuilder();
    }

    public function testEq(): void
    {
        $expr = $this->expr->eq('title', 'Task');

        self::assertSame(
            [
                'title' => [
                    'eq' => 'Task',
                ],
            ],
            $expr->toArray()
        );
    }

    public function testContains(): void
    {
        $expr = $this->expr->contains('title', 'Task');

        self::assertSame(
            [
                'title' => [
                    'contains' => 'Task',
                ],
            ],
            $expr->toArray()
        );
    }

    public function testIsNull(): void
    {
        $expr = $this->expr->isNull('deleted_at');

        self::assertSame(
            [
                'deleted_at' => [
                    'isNull' => true,
                ],
            ],
            $expr->toArray()
        );
    }

    public function testOrX(): void
    {
        $expr = $this->expr->orX(
            $this->expr->eq('title', 'A'),
            $this->expr->eq('title', 'B')
        );

        self::assertSame(
            [
                'or' => [
                    [
                        'title' => [
                            'eq' => 'A',
                        ],
                    ],
                    [
                        'title' => [
                            'eq' => 'B',
                        ],
                    ],
                ],
            ],
            $expr->toArray()
        );
    }

    #[Test]
    public function itComparesValues(): void
    {
        $from = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        self::assertSame(['priority' => ['gt' => 2]], $this->expr->gt('priority', 2)->toArray());
        self::assertSame(['due_date' => ['gte' => $from]], $this->expr->gte('due_date', $from)->toArray());
        self::assertSame(['priority' => ['lt' => 5]], $this->expr->lt('priority', 5)->toArray());
        self::assertSame(['due_date' => ['lte' => $from]], $this->expr->lte('due_date', $from)->toArray());
    }

    #[Test]
    public function itMatchesTheFieldsThatAreNotNull(): void
    {
        self::assertSame(['due_date' => ['isNull' => false]], $this->expr->isNotNull('due_date')->toArray());
    }

    #[Test]
    public function itExcludesValuesWithOneInequalityEach(): void
    {
        self::assertSame(
            ['and' => [['status' => ['neq' => 'done']], ['status' => ['neq' => 'archived']]]],
            $this->expr->notIn('status', ['done', 'archived'])->toArray(),
        );
    }

    #[Test]
    public function itRejectsAnExclusionWithoutValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->expr->notIn('status', []);
    }
}
