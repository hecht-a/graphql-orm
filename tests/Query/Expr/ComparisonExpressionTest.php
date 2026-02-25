<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query\Expr;

use GraphqlOrm\Query\Expr\ComparisonExpression;
use PHPUnit\Framework\TestCase;

final class ComparisonExpressionTest extends TestCase
{
    public function testToArray(): void
    {
        $expr = new ComparisonExpression('title', 'eq', 'Task 1');

        self::assertSame(
            [
                'title' => [
                    'eq' => 'Task 1',
                ],
            ],
            $expr->toArray()
        );
    }

    public function testSupportsArrayValue(): void
    {
        $expr = new ComparisonExpression('status', 'in', ['OPEN', 'DONE']);

        self::assertSame(
            [
                'status' => [
                    'in' => ['OPEN', 'DONE'],
                ],
            ],
            $expr->toArray()
        );
    }
}
