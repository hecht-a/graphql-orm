<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Dialect;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Query\QueryOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DataApiBuilderDialectTest extends TestCase
{
    #[Test]
    public function itFetchesThePageAfterAnyCursor(): void
    {
        $options = new QueryOptions();
        $options->paginate = true;
        $options->limit = 2;
        $options->cursor = '0';

        self::assertSame(['first' => 2, 'after' => '0'], (new DataApiBuilderDialect())->applyQueryOptions([], $options));
    }

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
