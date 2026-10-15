<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Dialect;

use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\QueryOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DefaultDialectQueryOptionsTest extends TestCase
{
    #[Test]
    public function itAppliesTheLimitAsAFirstArgument(): void
    {
        $options = new QueryOptions();
        $options->limit = 10;

        $arguments = (new DefaultDialect())->applyQueryOptions([], $options);

        self::assertSame(['first' => 10], $arguments);
    }

    #[Test]
    public function itRejectsOrderingInsteadOfSilentlyIgnoringIt(): void
    {
        $options = new QueryOptions();
        $options->orderBy = ['createdAt' => Direction::Desc];

        $this->expectException(LogicException::class);
        (new DefaultDialect())->applyQueryOptions([], $options);
    }

    #[Test]
    public function itRejectsPaginationInsteadOfSilentlyIgnoringIt(): void
    {
        $options = new QueryOptions();
        $options->paginate = true;

        $this->expectException(LogicException::class);
        (new DefaultDialect())->applyQueryOptions([], $options);
    }
}
