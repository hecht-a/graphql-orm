<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Dialect;

use GraphqlOrm\Dialect\CountingDialect;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\HasuraDialect;
use GraphqlOrm\Dialect\PostGraphileDialect;
use GraphqlOrm\Dialect\RelayDialect;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\GraphqlQueryCompiler;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\DefaultGraphqlWalker;
use GraphqlOrm\Schema\CountableFieldResolver;
use GraphqlOrm\Tests\Fixtures\FakeDataApiBuilderSchema;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

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

        self::assertSame($expectedQuery, self::print($field));
        self::assertSame(3, $dialect->readCount($data, $field->name));
    }

    #[Test]
    public function itCountsOnTheNumericIdentifierWithoutIntrospectingTheSchema(): void
    {
        $client = new FakeGraphqlClient(FakeDataApiBuilderSchema::introspectionResponse());

        $query = self::print($this->dataApiBuilder($client)->countField('labels', [], 'code'));

        self::assertSame('labels { groupBy { aggregations { count(field: code) } } }', $query);
        self::assertSame(0, $client->callCount);
    }

    #[Test]
    public function itCountsOnAFieldNeverNullWithoutNumericIdentifier(): void
    {
        $dialect = $this->dataApiBuilder(new FakeGraphqlClient(FakeDataApiBuilderSchema::introspectionResponse()));

        $query = self::print($dialect->countField('labels', ['filter' => ['name' => ['eq' => 'A']]], null));

        self::assertSame('labels(filter: { name: { eq: "A" } }) { groupBy { aggregations { count(field: position) } } }', $query);
    }

    #[Test]
    public function itExplainsWhyNoFieldCanBeCounted(): void
    {
        $dialect = $this->dataApiBuilder(new FakeGraphqlClient(FakeDataApiBuilderSchema::introspectionResponse()));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"notes" has no numeric field that is never null');
        $dialect->countField('notes', [], null);
    }

    #[Test]
    public function itExplainsThatCountingWithoutNumericIdentifierNeedsTheSchema(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('count() needs a numeric identifier, or the introspection of the schema');
        (new DataApiBuilderDialect())->countField('labels', [], null);
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
        $this->expectExceptionMessage('Invalid GraphQL response: expected a number for the count of "tasks", got "null".');

        (new RelayDialect())->readCount(['tasks' => ['totalCount' => null]], 'tasks');
    }

    #[Test]
    public function itRejectsAHasuraCountThatIsNoNumber(): void
    {
        $this->expectException(InvalidGraphqlResponseException::class);
        $this->expectExceptionMessage('Invalid GraphQL response: expected a number for the count of "tasks_aggregate", got "string".');

        (new HasuraDialect())->readCount(['tasks_aggregate' => ['aggregate' => ['count' => 'three']]], 'tasks_aggregate');
    }

    private function dataApiBuilder(FakeGraphqlClient $client): DataApiBuilderDialect
    {
        return new DataApiBuilderDialect(new CountableFieldResolver($client, new ArrayAdapter(), 3600));
    }

    private static function print(FieldNode $field): string
    {
        $query = new QueryNode();
        $query->fields[] = $field;

        $graphql = (new GraphqlQueryCompiler(new DefaultGraphqlWalker()))->compile($query, new QueryOptions());

        return (string) preg_replace('/\s+/', ' ', trim(substr($graphql, \strlen('query {'), -1)));
    }
}
