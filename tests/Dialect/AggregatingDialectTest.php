<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Dialect;

use GraphqlOrm\Dialect\AggregatingDialect;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\HasuraDialect;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Query\Aggregate;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\GraphqlQueryCompiler;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\DefaultGraphqlWalker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AggregatingDialectTest extends TestCase
{
    /**
     * @return iterable<string, array{AggregatingDialect, array<string, mixed>, string, array<string, mixed>}>
     */
    public static function dialects(): iterable
    {
        yield 'Data API Builder' => [
            new DataApiBuilderDialect(),
            ['filter' => ['done' => ['eq' => false]]],
            'tasks(filter: { done: { eq: false } }) { groupBy { aggregations { sum(field: estimate) } } }',
            ['tasks' => ['groupBy' => [['aggregations' => ['sum' => 12.5]]]]],
        ];
        yield 'Hasura' => [
            new HasuraDialect(),
            ['where' => ['done' => ['_eq' => false]]],
            'tasks_aggregate(where: { done: { _eq: false } }) { aggregate { sum { estimate } } }',
            ['tasks_aggregate' => ['aggregate' => ['sum' => ['estimate' => 12.5]]]],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('dialects')]
    public function itAggregatesAFieldOfTheEntitiesMatchingTheArguments(AggregatingDialect $dialect, array $arguments, string $expectedQuery, array $data): void
    {
        $field = $dialect->aggregateField('tasks', $arguments, Aggregate::Sum, 'estimate');
        $query = new QueryNode();
        $query->fields[] = $field;

        $graphql = (new GraphqlQueryCompiler(new DefaultGraphqlWalker()))->compile($query, new QueryOptions());

        self::assertSame($expectedQuery, preg_replace('/\s+/', ' ', trim(substr($graphql, \strlen('query {'), -1))));
        self::assertSame(12.5, $dialect->readAggregate($data, $field->name, Aggregate::Sum, 'estimate'));
    }

    #[Test]
    public function itNamesEachAggregateAsTheServers(): void
    {
        $dialect = new HasuraDialect();

        self::assertSame(
            ['sum', 'avg', 'min', 'max'],
            array_map(fn (Aggregate $aggregate) => $dialect->aggregateField('tasks', [], $aggregate, 'id')->selectionSet?->fields[0]->selectionSet?->fields[0]->name, Aggregate::cases()),
        );
    }

    #[Test]
    public function itReadsNullWhenNoEntityMatches(): void
    {
        self::assertNull((new DataApiBuilderDialect())->readAggregate(['tasks' => ['groupBy' => [['aggregations' => ['max' => null]]]]], 'tasks', Aggregate::Max, 'id'));
        self::assertNull((new DataApiBuilderDialect())->readAggregate(['tasks' => ['groupBy' => []]], 'tasks', Aggregate::Max, 'id'));
        self::assertNull((new HasuraDialect())->readAggregate(['tasks_aggregate' => ['aggregate' => ['max' => ['id' => null]]]], 'tasks_aggregate', Aggregate::Max, 'id'));
    }

    #[Test]
    public function itReadsTheDatesAsSent(): void
    {
        $data = ['tasks_aggregate' => ['aggregate' => ['max' => ['due_date' => '2026-02-01T09:00:00+00:00']]]];

        self::assertSame('2026-02-01T09:00:00+00:00', (new HasuraDialect())->readAggregate($data, 'tasks_aggregate', Aggregate::Max, 'due_date'));
    }

    #[Test]
    public function itRejectsAResponseWithoutAggregate(): void
    {
        $this->expectException(InvalidGraphqlResponseException::class);
        $this->expectExceptionMessage('Invalid GraphQL response: expected a number for the sum of "id", got "array".');

        (new HasuraDialect())->readAggregate(['tasks_aggregate' => ['aggregate' => ['sum' => ['id' => ['nested']]]]], 'tasks_aggregate', Aggregate::Sum, 'id');
    }
}
