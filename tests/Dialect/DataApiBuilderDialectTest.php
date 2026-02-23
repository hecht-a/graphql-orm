<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Dialect;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use PHPUnit\Framework\TestCase;

final class DataApiBuilderDialectTest extends TestCase
{
    public function testExtractCollectionReturnsItems(): void
    {
        $dialect = new DataApiBuilderDialect();
        $data = [
            'items' => [
                ['id' => 1],
                ['id' => 2],
            ],
        ];

        self::assertSame($data['items'], $dialect->extractCollection($data));
    }

    public function testExtractCollectionReturnsEmptyWhenNoItems(): void
    {
        $dialect = new DataApiBuilderDialect();

        self::assertSame([], $dialect->extractCollection([]));
    }

    public function testExtractCollectionReturnsEmptyWhenItemsNull(): void
    {
        $dialect = new DataApiBuilderDialect();

        self::assertSame([], $dialect->extractCollection([
            'items' => null,
        ]));
    }
}
