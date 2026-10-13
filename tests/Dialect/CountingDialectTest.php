<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Dialect;

use GraphqlOrm\Dialect\CountingDialect;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\HasuraDialect;
use GraphqlOrm\Dialect\PostGraphileDialect;
use GraphqlOrm\Dialect\RelayDialect;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\GraphqlQueryCompiler;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\DefaultGraphqlWalker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CountingDialectTest extends TestCase
{
    /**
     * @return iterable<string, array{CountingDialect, array<string, mixed>, string, array<string, mixed>}>
     */
    public static function dialects(): iterable
    {
        yield 'Data API Builder' => [
            new DataApiBuilderDialect(),
            ['filter' => ['done' => ['eq' => false]]],
            'tasks(filter: { done: { eq: false } }) { groupBy { aggregations { count(field: id) } } }',
            ['tasks' => ['groupBy' => [['aggregations' => ['count' => 3]]]]],
        ];
        yield 'Hasura' => [
            new HasuraDialect(),
            ['where' => ['done' => ['_eq' => false]]],
            'tasks_aggregate(where: { done: { _eq: false } }) { aggregate { count } }',
            ['tasks_aggregate' => ['aggregate' => ['count' => 3]]],
        ];
        yield 'Relay' => [
            new RelayDialect(),
            ['done' => false],
            'tasks(done: false) { totalCount }',
            ['tasks' => ['totalCount' => 3]],
        ];
        yield 'PostGraphile' => [
            new PostGraphileDialect(),
            ['condition' => ['done' => false]],
            'tasks(condition: { done: false }) { totalCount }',
            ['tasks' => ['totalCount' => 3]],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('dialects')]
    public function itCountsTheEntitiesMatchingTheArguments(CountingDialect $dialect, array $arguments, string $expectedQuery, array $data): void
    {
        $field = $dialect->countField('tasks', $arguments, 'id');
        $query = new QueryNode();
        $query->fields[] = $field;

        $graphql = (new GraphqlQueryCompiler(new DefaultGraphqlWalker()))->compile($query, new QueryOptions());

        self::assertSame($expectedQuery, preg_replace('/\s+/', ' ', trim(substr($graphql, \strlen('query {'), -1))));
        self::assertSame(3, $dialect->readCount($data, $field->name));
    }

    #[Test]
    public function itCountsNothingForAnEmptyDataApiBuilderGroup(): void
    {
        self::assertSame(0, (new DataApiBuilderDialect())->readCount(['tasks' => ['groupBy' => []]], 'tasks'));
    }

    #[Test]
    public function itRejectsAResponseWithoutCount(): void
    {
        $this->expectException(InvalidGraphqlResponseException::class);
        (new RelayDialect())->readCount(['tasks' => ['totalCount' => null]], 'tasks');
    }
}
