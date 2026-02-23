<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Dialect;

use GraphqlOrm\Dialect\DefaultDialect;
use PHPUnit\Framework\TestCase;

final class DefaultDialectTest extends TestCase
{
    public function testExtractCollectionReturnsSameArray(): void
    {
        $dialect = new DefaultDialect();
        $data = [
            ['id' => 1],
            ['id' => 2],
        ];

        self::assertSame($data, $dialect->extractCollection($data));
    }

    public function testExtractCollectionReturnsEmptyArray(): void
    {
        $dialect = new DefaultDialect();

        self::assertSame([], $dialect->extractCollection([]));
    }
}
