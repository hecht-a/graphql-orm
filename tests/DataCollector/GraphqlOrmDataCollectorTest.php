<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\DataCollector;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Query\GraphqlQueryTrace;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
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
    public function itGivesEachQueryAsACurlCommand(): void
    {
        $trace = self::traceOf("query (\$id: Int) {\n  tasks(id: \$id) { title }\n}");
        $trace->endpoint = 'https://graphql.example.com/graphql';
        $trace->variables = ['id' => 3];

        $this->collector->addQuery($trace);

        self::assertSame(
            "curl -X POST 'https://graphql.example.com/graphql' -H 'Content-Type: application/json' --data-raw '{\"query\":\"query (\$id: Int) {\\n  tasks(id: \$id) { title }\\n}\",\"variables\":{\"id\":3}}'",
            $this->collector->getQueries()[0]['curl'],
        );
    }

    #[Test]
    public function itQuotesTheCurlCommandForTheShell(): void
    {
        $trace = self::traceOf('query { users(name: "O\'Brien") { id } }');
        $trace->endpoint = 'https://graphql.example.com/graphql';

        $this->collector->addQuery($trace);

        self::assertSame(
            "curl -X POST 'https://graphql.example.com/graphql' -H 'Content-Type: application/json' --data-raw '{\"query\":\"query { users(name: \\\"O'\\''Brien\\\") { id } }\",\"variables\":{}}'",
            $this->collector->getQueries()[0]['curl'],
        );
    }

    #[Test]
    public function itHasNoCurlCommandForAQueryWithoutEndpoint(): void
    {
        $this->collector->addQuery(self::traceOf('query { tasks { id } }'));

        self::assertNull($this->collector->getQueries()[0]['curl']);
    }

    #[Test]
    public function itIsNamedForTheProfilerPanel(): void
    {
        self::assertSame('graphql_orm', $this->collector->getName());
    }

    #[Test]
    public function itNamesTheRootFieldOfEachQuery(): void
    {
        $this->collector->addQuery(self::traceOf('query { tasks(first: 10) { id } }'));
        $this->collector->addQuery(self::traceOf("query(\$filter: TaskFilterInput) {\n  users(filter: \$filter) { id }\n}"));
        $this->collector->addQuery(self::traceOf('{ __schema { types { name } } }'));

        self::assertSame(['tasks', 'users', '__schema'], array_column($this->collector->getQueries(), 'root_field'));
    }

    #[Test]
    public function itSumsTheEntitiesHydratedAndTheBytesReceived(): void
    {
        foreach ([[4, 2048], [3, 1024]] as [$entities, $bytes]) {
            $trace = self::traceOf('query { tasks { id } }');
            $trace->hydratedEntities = $entities;
            $trace->responseSize = $bytes;
            $this->collector->addQuery($trace);
        }

        self::assertSame(7, $this->collector->getHydratedEntityCount());
        self::assertSame(3072, $this->collector->getResponseSize());
    }

    #[Test]
    public function itPlacesTheQueriesOnTheTimelineOfTheRequest(): void
    {
        foreach ([[100.010, 5.0], [100.050, 20.0]] as [$startedAt, $duration]) {
            $trace = self::trace(duration: $duration, errors: null);
            $trace->startedAt = $startedAt;
            $this->collector->addQuery($trace);
        }
        $request = new Request(server: ['REQUEST_TIME_FLOAT' => 100.0]);

        $this->collector->collect($request, new Response());

        self::assertEqualsWithDelta([10.0, 50.0], $this->collector->getQueryOffsets(), 0.001);
        self::assertEqualsWithDelta(70.0, $this->collector->getTimelineSpan(), 0.001);
    }

    #[Test]
    public function itPlacesTheQueriesFromTheFirstOneWithoutRequest(): void
    {
        foreach ([100.010, 100.050] as $startedAt) {
            $trace = self::trace(duration: 1.0, errors: null);
            $trace->startedAt = $startedAt;
            $this->collector->addQuery($trace);
        }

        self::assertEqualsWithDelta([0.0, 40.0], $this->collector->getQueryOffsets(), 0.001);
    }

    #[Test]
    public function itGroupsTheLazyLoadsOfARelationLoadedOneByOne(): void
    {
        foreach ([['App\\Entity\\Task::$assignee', 2.0, 18], ['App\\Entity\\Task::$assignee', 3.0, 18], ['App\\Entity\\Task::$project', 1.0, 30], ['App\\Entity\\Task::$assignee', 4.0, 21]] as [$relation, $duration, $line]) {
            $this->collector->addQuery(self::lazyLoad($relation, $duration, $line));
        }

        $loads = $this->collector->getLazyLoadsByRelation();

        self::assertCount(1, $loads);
        self::assertSame('App\\Entity\\Task::$assignee', $loads[0]['relation']);
        self::assertSame(3, $loads[0]['count']);
        self::assertSame([1, 2, 4], $loads[0]['queries']);
        self::assertEqualsWithDelta(9.0, $loads[0]['duration'], 0.001);
        self::assertSame(['templates/task/board.html.twig:18', 'templates/task/board.html.twig:21'], $loads[0]['callers']);
    }

    #[Test]
    public function itLeavesTheLazyLoadsOutOfTheRepeatedQueries(): void
    {
        foreach ([1, 2, 3] as $line) {
            $this->collector->addQuery(self::lazyLoad('App\\Entity\\Task::$assignee', 1.0, $line));
        }

        self::assertSame([], $this->collector->getRepeatedQueries());
    }

    #[Test]
    public function itGroupsTheEntitiesServedByTheIdentityMap(): void
    {
        $this->collector->addIdentityMapHit('App\\Entity\\Task', 12, 'src/Controller/TaskController.php:51');
        $this->collector->addIdentityMapHit('App\\Entity\\User', 3, null);
        $this->collector->addIdentityMapHit('App\\Entity\\Task', 12, 'src/Controller/TaskController.php:51');

        self::assertSame(3, $this->collector->getIdentityMapHitCount());
        self::assertSame([
            ['class' => 'App\\Entity\\Task', 'identifier' => 12, 'count' => 2, 'callers' => ['src/Controller/TaskController.php:51']],
            ['class' => 'App\\Entity\\User', 'identifier' => 3, 'count' => 1, 'callers' => []],
        ], $this->collector->getIdentityMapHits());
    }

    #[Test]
    public function itKeepsTheConfigurationInTheProfile(): void
    {
        $collector = new GraphqlOrmDataCollector(configuration: ['max_depth' => 1, 'identity_map' => true]);

        $collector->collect(new Request(), new Response());

        self::assertSame(['max_depth' => 1, 'identity_map' => true], $collector->getConfiguration());
    }

    #[Test]
    public function itKeepsTheSlowQueryThresholdInTheStoredProfile(): void
    {
        $collector = new GraphqlOrmDataCollector(slowQueryThreshold: 500.0);
        $collector->addQuery(self::trace(duration: 750.0, errors: null));
        $collector->collect(new Request(), new Response());

        $restored = unserialize(serialize($collector));

        self::assertInstanceOf(GraphqlOrmDataCollector::class, $restored);
        self::assertSame(500.0, $restored->getSlowQueryThreshold());
        self::assertSame(1, $restored->getSlowQueryCount());
    }

    #[Test]
    public function itSumsTheHydrationDurations(): void
    {
        foreach ([1.5, 2.25] as $hydration) {
            $trace = self::trace(duration: 10.0, errors: null);
            $trace->hydrationDuration = $hydration;
            $this->collector->addQuery($trace);
        }

        self::assertEqualsWithDelta(3.75, $this->collector->getHydrationDuration(), 0.001);
    }

    #[Test]
    public function itSuggestsOneFindByForTheEntitiesFoundOneByOne(): void
    {
        foreach ([3, 7, 12] as $id) {
            $trace = self::traceOf(\sprintf('query { tasks(id: %d) { id } }', $id));
            $trace->origin = 'find';
            $trace->entityClass = FakeTask::class;
            $trace->identifierProperty = 'id';
            $this->collector->addQuery($trace);
        }

        $repeated = $this->collector->getRepeatedQueries()[0];

        self::assertSame([FakeTask::class, 'id'], [$repeated['find_entity'], $repeated['find_identifier']]);
    }

    #[Test]
    public function itSuggestsNothingForRepeatedQueriesOfDifferentOrigins(): void
    {
        foreach (['find', 'findBy', 'find'] as $index => $origin) {
            $trace = self::traceOf(\sprintf('query { tasks(id: %d) { id } }', $index));
            $trace->origin = $origin;
            $trace->entityClass = FakeTask::class;
            $trace->identifierProperty = $origin === 'find' ? 'id' : null;
            $this->collector->addQuery($trace);
        }

        self::assertNull($this->collector->getRepeatedQueries()[0]['find_entity']);
    }

    private static function lazyLoad(string $relation, float $duration, int $line): GraphqlQueryTrace
    {
        $trace = self::traceOf('query { users(id: 3) { id name } }');
        $trace->origin = 'lazy';
        $trace->lazyRelation = $relation;
        $trace->duration = $duration;
        $trace->caller = ['file' => 'templates/task/board.html.twig', 'line' => $line, 'class' => null, 'function' => null];

        return $trace;
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
