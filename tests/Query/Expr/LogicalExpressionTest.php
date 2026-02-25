<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query\Expr;

use GraphqlOrm\Query\Expr\ComparisonExpression;
use GraphqlOrm\Query\Expr\LogicalExpression;
use PHPUnit\Framework\TestCase;

final class LogicalExpressionTest extends TestCase
{
    public function testAndExpression(): void
    {
        $expr =
            new LogicalExpression(
                'and',
                [
                    new ComparisonExpression('title', 'contains', 'Task'),
                ]
            );

        self::assertSame(
            [
                'and' => [
                    [
                        'title' => [
                            'contains' => 'Task',
                        ],
                    ],
                ],
            ],

            $expr->toArray()
        );
    }
}
