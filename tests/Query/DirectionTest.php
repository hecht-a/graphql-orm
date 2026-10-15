<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\Query\Direction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DirectionTest extends TestCase
{
    #[Test]
    public function itNamesItsCasesInPascalCaseWithTheSameValues(): void
    {
        self::assertSame(['Asc' => 'ASC', 'Desc' => 'DESC'], array_column(array_map(fn (Direction $direction) => ['name' => $direction->name, 'value' => $direction->value], Direction::cases()), 'value', 'name'));
    }

    #[Test]
    public function itNoLongerHasTheUppercaseNamesOfVersion48(): void
    {
        self::assertFalse(\defined(Direction::class . '::ASC'));
        self::assertFalse(\defined(Direction::class . '::DESC'));
    }
}
