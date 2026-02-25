<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query\Expr;

use GraphqlOrm\Query\Expr\ExpressionBuilder;
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
}
