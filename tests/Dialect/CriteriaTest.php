<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Dialect;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\DefaultDialect;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CriteriaTest extends TestCase
{
    #[Test]
    public function itSendsCriteriaAsRootArgumentsWithTheDefaultDialect(): void
    {
        $arguments = (new DefaultDialect())->applyCriteria(['title' => 'A', 'done' => false]);

        self::assertSame(['title' => 'A', 'done' => false], $arguments);
    }

    #[Test]
    public function itTurnsCriteriaIntoAnEqualityFilterWithDataApiBuilder(): void
    {
        $arguments = (new DataApiBuilderDialect())->applyCriteria(['title' => 'A', 'done' => false]);

        self::assertSame(['filter' => ['title' => ['eq' => 'A'], 'done' => ['eq' => false]]], $arguments);
    }

    #[Test]
    public function itTurnsANullCriterionIntoAnIsNullFilterWithDataApiBuilder(): void
    {
        $arguments = (new DataApiBuilderDialect())->applyCriteria(['deletedAt' => null]);

        self::assertSame(['filter' => ['deletedAt' => ['isNull' => true]]], $arguments);
    }

    #[Test]
    public function itSendsNoFilterForEmptyCriteriaWithDataApiBuilder(): void
    {
        $arguments = (new DataApiBuilderDialect())->applyCriteria([]);

        self::assertSame([], $arguments);
    }
}
