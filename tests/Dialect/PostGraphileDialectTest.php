<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Dialect;

use GraphqlOrm\Dialect\PostGraphileDialect;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\EnumLiteral;
use GraphqlOrm\Query\Expr\ExpressionBuilder;
use GraphqlOrm\Query\QueryOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PostGraphileDialectTest extends TestCase
{
    private PostGraphileDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new PostGraphileDialect();
    }

    #[Test]
    public function itSendsCriteriaAsACondition(): void
    {
        self::assertSame(['condition' => ['done' => true, 'userId' => null]], $this->dialect->applyCriteria(['done' => true, 'userId' => null]));
        self::assertSame([], $this->dialect->applyCriteria([]));
    }

    #[Test]
    public function itOrdersWithTheEnumValuesOfTheFields(): void
    {
        $options = new QueryOptions();
        $options->orderBy = ['dueDate' => Direction::Desc, 'id' => Direction::Asc];
        $options->limit = 5;

        $arguments = $this->dialect->applyQueryOptions([], $options);

        self::assertEquals(['orderBy' => [new EnumLiteral('DUE_DATE_DESC'), new EnumLiteral('ID_ASC')], 'first' => 5], $arguments);
    }

    #[Test]
    public function itPaginatesWithRelayCursors(): void
    {
        $options = new QueryOptions();
        $options->paginate = true;
        $options->limit = 2;
        $options->cursor = 'c1';

        self::assertSame(['first' => 2, 'after' => 'c1'], $this->dialect->applyQueryOptions([], $options));
        self::assertTrue($this->dialect->hasNextPage(['pageInfo' => ['hasNextPage' => true, 'endCursor' => 'c2']]));
        self::assertSame('c2', $this->dialect->endCursor(['pageInfo' => ['hasNextPage' => true, 'endCursor' => 'c2']]));
    }

    #[Test]
    public function itReadsTheNodesOfTheConnections(): void
    {
        self::assertSame([['id' => 1]], $this->dialect->extractCollection(['edges' => [['node' => ['id' => 1]]]]));
    }

    #[Test]
    public function itRejectsQueryBuilderFiltersWhichNeedAPlugin(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('postgraphile-plugin-connection-filter');
        $this->dialect->applyFilter((new ExpressionBuilder())->eq('title', 'A'));
    }

    #[Test]
    public function itSendsNoFilterWithoutExpression(): void
    {
        self::assertSame([], $this->dialect->applyFilter(null));
    }
}
