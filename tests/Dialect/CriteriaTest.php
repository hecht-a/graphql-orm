<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Dialect;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\HasuraDialect;
use GraphqlOrm\Dialect\PostGraphileDialect;
use GraphqlOrm\Exception\LogicException;
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

    #[Test]
    public function itTurnsAListCriterionIntoAnInFilterWithDataApiBuilder(): void
    {
        $arguments = (new DataApiBuilderDialect())->applyCriteria(['id' => [1, 3], 'done' => false]);

        self::assertSame(['filter' => ['id' => ['in' => [1, 3]], 'done' => ['eq' => false]]], $arguments);
    }

    #[Test]
    public function itTurnsAListCriterionIntoAnInFilterWithHasura(): void
    {
        $arguments = (new HasuraDialect())->applyCriteria(['id' => [1, 3]]);

        self::assertSame(['where' => ['id' => ['_in' => [1, 3]]]], $arguments);
    }

    #[Test]
    public function itRejectsAListCriterionWithPostGraphile(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"id"');

        (new PostGraphileDialect())->applyCriteria(['id' => [1, 3]]);
    }
}
