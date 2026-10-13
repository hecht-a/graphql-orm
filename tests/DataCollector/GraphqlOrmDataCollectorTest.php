<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\DataCollector;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Query\GraphqlQueryTrace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class GraphqlOrmDataCollectorTest extends TestCase
{
    private GraphqlOrmDataCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new GraphqlOrmDataCollector();
    }

    #[Test]
    public function itRecordsTheQueryTrace(): void
    {
        $trace = self::trace(duration: 12.5, errors: null);
        $trace->graphql = 'query { tasks { id } }';
        $trace->endpoint = 'https://graphql.example.com/graphql';
        $trace->variables = ['first' => 10];
        $trace->hydratedCount = 3;
        $trace->hydratedEntities = 4;
        $trace->hydratedRelations = 1;
        $trace->hydratedCollections = 2;
        $trace->hydrationMaxDepth = 1;

        $this->collector->addQuery($trace);

        $query = $this->collector->getQueries()[0];
        self::assertSame('query { tasks { id } }', $query['graphql']);
        self::assertSame('https://graphql.example.com/graphql', $query['endpoint']);
        self::assertSame(['first' => 10], $query['variables']);
        self::assertSame([3, 4, 1, 2, 1], [
            $query['hydrated_count'],
            $query['hydrated_entities'],
            $query['hydrated_relations'],
            $query['hydrated_collections'],
            $query['depth_used'],
        ]);
    }

    #[Test]
    public function itSumsTheDurationsAndCountsTheQueriesWithErrors(): void
    {
        $this->collector->addQuery(self::trace(duration: 10.0, errors: null));
        $this->collector->addQuery(self::trace(duration: 2.5, errors: [['message' => 'Field "secret" is restricted']]));
        $this->collector->addQuery(self::trace(duration: 1, errors: []));

        self::assertSame(3, $this->collector->getQueryCount());
        self::assertSame(13.5, $this->collector->getTotalDuration());
        self::assertSame(1, $this->collector->getErrorCount());
    }

    #[Test]
    public function itHasNoQueryForARequestWithoutGraphql(): void
    {
        $this->collector->collect(new Request(), new Response());

        self::assertSame([], $this->collector->getQueries());
        self::assertSame(0.0, $this->collector->getTotalDuration());
    }

    #[Test]
    public function itForgetsTheQueriesOnReset(): void
    {
        $this->collector->addQuery(self::trace(duration: 1.0, errors: null));

        $this->collector->reset();

        self::assertSame(0, $this->collector->getQueryCount());
    }

    #[Test]
    public function itGroupsTheQueriesRepeatedWithOtherValues(): void
    {
        foreach (['users(id: 1) { id name }', 'tasks { id }', 'users(id: 2) { id name }', 'users(id: 3) { id name }'] as $graphql) {
            $this->collector->addQuery(self::traceOf('query { ' . $graphql . ' }'));
        }

        $repeated = $this->collector->getRepeatedQueries();

        self::assertCount(1, $repeated);
        self::assertSame('query { users(id: ***) { id name } }', $repeated[0]['shape']);
        self::assertSame(3, $repeated[0]['count']);
        self::assertSame([1, 3, 4], $repeated[0]['queries']);
    }

    #[Test]
    public function itDoesNotReportAQueryRunTwice(): void
    {
        $this->collector->addQuery(self::traceOf('query { users(id: 1) { id } }'));
        $this->collector->addQuery(self::traceOf('query { users(id: 2) { id } }'));

        self::assertSame([], $this->collector->getRepeatedQueries());
    }

    #[Test]
    public function itGroupsQueriesWhoseValuesAreAlreadyMasked(): void
    {
        foreach ([1, 2, 3] as $_) {
            $this->collector->addQuery(self::traceOf('query { users(filter: { id: { in: ["***"] } }) { id } }'));
        }

        self::assertSame(3, $this->collector->getRepeatedQueries()[0]['count'] ?? null);
    }

    #[Test]
    public function itIsNamedForTheProfilerPanel(): void
    {
        self::assertSame('graphql_orm', $this->collector->getName());
    }

    /**
     * @param array<string|int, mixed>|null $errors
     */
    private static function trace(float|int $duration, ?array $errors): GraphqlQueryTrace
    {
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $trace->duration = $duration;
        $trace->errors = $errors;

        return $trace;
    }

    private static function traceOf(string $graphql): GraphqlQueryTrace
    {
        $trace = new GraphqlQueryTrace();
        $trace->graphql = $graphql;

        return $trace;
    }
}
