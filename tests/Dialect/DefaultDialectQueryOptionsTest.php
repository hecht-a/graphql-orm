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
        // Arrange
        $options = new QueryOptions();
        $options->limit = 10;

        // Act
        $arguments = (new DefaultDialect())->applyQueryOptions([], $options);

        // Assert
        self::assertSame(['first' => 10], $arguments);
    }

    #[Test]
    public function itRejectsOrderingInsteadOfSilentlyIgnoringIt(): void
    {
        // Arrange
        $options = new QueryOptions();
        $options->orderBy = ['createdAt' => Direction::DESC];

        // Act & Assert
        $this->expectException(LogicException::class);
        (new DefaultDialect())->applyQueryOptions([], $options);
    }

    #[Test]
    public function itRejectsPaginationInsteadOfSilentlyIgnoringIt(): void
    {
        // Arrange
        $options = new QueryOptions();
        $options->paginate = true;

        // Act & Assert
        $this->expectException(LogicException::class);
        (new DefaultDialect())->applyQueryOptions([], $options);
    }
}
