<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Dialect;

use GraphqlOrm\Dialect\RelayDialect;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\Expr\ExpressionBuilder;
use GraphqlOrm\Query\QueryOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RelayDialectTest extends TestCase
{
    private RelayDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new RelayDialect();
    }

    #[Test]
    public function itExtractsTheNodesOfTheEdges(): void
    {
        $nodes = $this->dialect->extractCollection(['edges' => [['node' => ['id' => 1]], ['node' => ['id' => 2]]]]);

        self::assertSame([['id' => 1], ['id' => 2]], $nodes);
    }

    #[Test]
    public function itExtractsNoNodeFromAConnectionWithoutEdges(): void
    {
        self::assertSame([], $this->dialect->extractCollection(['edges' => null]));
    }

    #[Test]
    public function itReadsThePageInfo(): void
    {
        $root = ['edges' => [], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'c2']];

        self::assertTrue($this->dialect->hasNextPage($root));
        self::assertSame('c2', $this->dialect->endCursor($root));
    }

    #[Test]
    public function itPaginatesWithFirstAndAfter(): void
    {
        $options = new QueryOptions();
        $options->limit = 10;
        $options->paginate = true;
        $options->cursor = 'c1';

        $arguments = $this->dialect->applyQueryOptions(['status' => 'open'], $options);

        self::assertSame(['status' => 'open', 'first' => 10, 'after' => 'c1'], $arguments);
    }

    #[Test]
    public function itRejectsOrderingWhichRelayDoesNotStandardize(): void
    {
        $options = new QueryOptions();
        $options->orderBy = ['title' => Direction::Asc];

        $this->expectException(LogicException::class);
        $this->dialect->applyQueryOptions([], $options);
    }

    #[Test]
    public function itSendsCriteriaAndFiltersAsRootArguments(): void
    {
        $expr = new ExpressionBuilder();

        self::assertSame(['status' => 'open'], $this->dialect->applyCriteria(['status' => 'open']));
        self::assertSame(['title' => ['eq' => 'A']], $this->dialect->applyFilter($expr->eq('title', 'A')));
    }
}
