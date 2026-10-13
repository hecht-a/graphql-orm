<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Dialect;

use GraphqlOrm\Dialect\HasuraDialect;
use GraphqlOrm\Dialect\HasuraOrderDirection;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\Expr\ExpressionBuilder;
use GraphqlOrm\Query\QueryOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HasuraDialectTest extends TestCase
{
    private HasuraDialect $dialect;

    private ExpressionBuilder $expr;

    protected function setUp(): void
    {
        $this->dialect = new HasuraDialect();
        $this->expr = new ExpressionBuilder();
    }

    #[Test]
    public function itTranslatesComparisonsToHasuraOperators(): void
    {
        $filter = $this->expr->andX(
            $this->expr->eq('status', 'open'),
            $this->expr->neq('priority', 1),
            $this->expr->in('id', [1, 2]),
            $this->expr->isNull('deleted_at'),
        );

        self::assertSame(['where' => ['_and' => [
            ['status' => ['_eq' => 'open']],
            ['priority' => ['_neq' => 1]],
            ['id' => ['_in' => [1, 2]]],
            ['deleted_at' => ['_is_null' => true]],
        ]]], $this->dialect->applyFilter($filter));
    }

    #[Test]
    public function itTranslatesTextSearchesToLikePatterns(): void
    {
        $filter = $this->expr->orX(
            $this->expr->contains('title', 'docs'),
            $this->expr->notContains('title', 'draft'),
            $this->expr->startsWith('code', 'A'),
            $this->expr->endsWith('code', 'Z'),
        );

        self::assertSame(['where' => ['_or' => [
            ['title' => ['_like' => '%docs%']],
            ['title' => ['_nlike' => '%draft%']],
            ['code' => ['_like' => 'A%']],
            ['code' => ['_like' => '%Z']],
        ]]], $this->dialect->applyFilter($filter));
    }

    #[Test]
    public function itEscapesLikeWildcardsInSearchedValues(): void
    {
        self::assertSame(
            ['where' => ['code' => ['_like' => '%A\_1\%%']]],
            $this->dialect->applyFilter($this->expr->contains('code', 'A_1%')),
        );
    }

    #[Test]
    public function itSendsCriteriaAsAWhereArgument(): void
    {
        self::assertSame(
            ['where' => ['status' => ['_eq' => 'open'], 'deleted_at' => ['_is_null' => true]]],
            $this->dialect->applyCriteria(['status' => 'open', 'deleted_at' => null]),
        );
        self::assertSame([], $this->dialect->applyCriteria([]));
    }

    #[Test]
    public function itOrdersAndLimitsWithHasuraArguments(): void
    {
        $options = new QueryOptions();
        $options->orderBy = ['title' => Direction::DESC, 'id' => Direction::ASC];
        $options->limit = 5;

        $arguments = $this->dialect->applyQueryOptions([], $options);

        self::assertSame(['order_by' => ['title' => HasuraOrderDirection::Desc, 'id' => HasuraOrderDirection::Asc], 'limit' => 5], $arguments);
    }

    #[Test]
    public function itPaginatesWithAnOffsetAndOneExtraRow(): void
    {
        $options = new QueryOptions();
        $options->paginate = true;
        $options->limit = 2;

        $firstPage = $this->dialect->applyQueryOptions([], $options);
        $options->cursor = '4';
        $thirdPage = $this->dialect->applyQueryOptions([], $options);

        self::assertSame(['limit' => 3], $firstPage);
        self::assertSame(['limit' => 3, 'offset' => 4], $thirdPage);
    }

    #[Test]
    public function itNeedsAPageSizeToPaginate(): void
    {
        $options = new QueryOptions();
        $options->paginate = true;

        $this->expectException(LogicException::class);
        $this->dialect->applyQueryOptions([], $options);
    }

    #[Test]
    public function itRejectsACursorThatIsNotAnOffset(): void
    {
        $options = new QueryOptions();
        $options->paginate = true;
        $options->limit = 2;
        $options->cursor = 'W3siaWQiOjJ9XQ==';

        $this->expectException(InvalidArgumentException::class);
        $this->dialect->applyQueryOptions([], $options);
    }

    #[Test]
    public function itReadsCollectionsAsPlainLists(): void
    {
        self::assertSame([['id' => 1]], $this->dialect->extractCollection([['id' => 1]]));
    }
}
