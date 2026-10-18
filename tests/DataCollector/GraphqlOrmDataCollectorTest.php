<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\DataCollector;

use GraphqlOrm\Converter\TypeConverterRegistry;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\GraphqlQueryTrace;
use GraphqlOrm\Tests\Fixtures\Cache\CachedTask;
use GraphqlOrm\Tests\Fixtures\Converter\DecimalMoneyConverter;
use GraphqlOrm\Tests\Fixtures\Converter\MoneyConverter;
use GraphqlOrm\Tests\Fixtures\Converter\Product;
use GraphqlOrm\Tests\Fixtures\Depth\ChainA;
use GraphqlOrm\Tests\Fixtures\Depth\ChainB;
use GraphqlOrm\Tests\Fixtures\Embedded\Money;
use GraphqlOrm\Tests\Fixtures\Embedded\Order;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeLabel;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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
    public function itCountsTheTimeWaitedForQueriesSentTogetherOnce(): void
    {
        foreach ([[100.0, 100.0], [100.0, 100.0], [100.02, 60.0], [100.5, 50.0]] as [$startedAt, $duration]) {
            $trace = self::trace(duration: $duration, errors: null);
            $trace->startedAt = $startedAt;
            $this->collector->addQuery($trace);
        }

        $this->collector->collect(new Request(server: ['REQUEST_TIME_FLOAT' => 100.0]), new Response());

        self::assertEqualsWithDelta(310.0, $this->collector->getTotalDuration(), 0.001);
        self::assertEqualsWithDelta(150.0, $this->collector->getWaitedDuration(), 0.001);
    }

    #[Test]
    public function itAlertsBeyondTheQueriesAndTheTimeConfigured(): void
    {
        $collector = new GraphqlOrmDataCollector(maxQueries: 1, maxQueryTime: 100.0);
        $collector->addQuery(self::trace(duration: 60.0, errors: null));

        self::assertFalse($collector->isOverMaxQueries());
        self::assertFalse($collector->isOverMaxQueryTime());

        $collector->addQuery(self::trace(duration: 60.0, errors: null));

        self::assertTrue($collector->isOverMaxQueries());
        self::assertTrue($collector->isOverMaxQueryTime());
    }

    #[Test]
    public function itDoesNotAlertWithoutThresholds(): void
    {
        $this->collector->addQuery(self::trace(duration: 6000.0, errors: null));

        self::assertFalse($this->collector->isOverMaxQueries());
        self::assertFalse($this->collector->isOverMaxQueryTime());
    }

    #[Test]
    public function itReportsTheQueriesRepeatedAsOftenAsConfigured(): void
    {
        $collector = new GraphqlOrmDataCollector(repeatedQueryThreshold: 2);
        $collector->addQuery(self::traceOf('query { tasks(filter: { id: { eq: 1 } }) { items { id } } }'));
        $collector->addQuery(self::traceOf('query { tasks(filter: { id: { eq: 2 } }) { items { id } } }'));

        self::assertCount(1, $collector->getRepeatedQueries());
        self::assertCount(0, $this->collector->getRepeatedQueries());
    }

    #[Test]
    public function itKeepsTheThresholdsInTheProfile(): void
    {
        $collector = new GraphqlOrmDataCollector(maxQueries: 1, maxQueryTime: 100.0, repeatedQueryThreshold: 2);
        $collector->collect(new Request(), new Response());

        $profiled = unserialize(serialize($collector));

        self::assertInstanceOf(GraphqlOrmDataCollector::class, $profiled);
        self::assertSame(1, $profiled->getMaxQueries());
        self::assertSame(100.0, $profiled->getMaxQueryTime());
        self::assertSame(2, $profiled->getRepeatedQueryThreshold());
    }

    #[Test]
    public function itCountsEveryQueryWhenTheirStartIsUnknown(): void
    {
        $this->collector->addQuery(self::trace(duration: 100.0, errors: null));
        $this->collector->addQuery(self::trace(duration: 50.0, errors: null));

        self::assertEqualsWithDelta(150.0, $this->collector->getWaitedDuration(), 0.001);
    }

    #[Test]
    public function itEndsTheTimelineAfterTheHydrationOfTheLastQuery(): void
    {
        $trace = self::trace(duration: 20.0, errors: null);
        $trace->startedAt = 100.050;
        $trace->hydrationDuration = 5.0;
        $this->collector->addQuery($trace);

        $this->collector->collect(new Request(server: ['REQUEST_TIME_FLOAT' => 100.0]), new Response());

        self::assertEqualsWithDelta(75.0, $this->collector->getTimelineSpan(), 0.001);
    }

    #[Test]
    public function itGradesTheTimelineWithRoundSteps(): void
    {
        $trace = self::trace(duration: 900.0, errors: null);
        $trace->startedAt = 100.044;
        $this->collector->addQuery($trace);

        $this->collector->collect(new Request(server: ['REQUEST_TIME_FLOAT' => 100.0]), new Response());

        self::assertSame([0.0, 250.0, 500.0, 750.0], $this->collector->getTimelineTicks());
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
    public function itGroupsTheQueriesByTheLineSendingThem(): void
    {
        foreach ([['TaskController.php', 18, 'findBy', FakeTask::class, 5.0], ['board.html.twig', 7, 'lazy', FakeLabel::class, 20.0], ['board.html.twig', 7, 'lazy', FakeLabel::class, 30.0], [null, null, 'introspection', null, 1.0]] as [$file, $line, $origin, $class, $duration]) {
            $trace = self::trace(duration: $duration, errors: null);
            $trace->caller = $file !== null ? ['file' => $file, 'line' => $line, 'class' => null, 'function' => null] : null;
            $trace->origin = $origin;
            $trace->entityClass = $class;
            $this->collector->addQuery($trace);
        }

        self::assertSame([
            ['caller' => 'board.html.twig:7', 'count' => 2, 'duration' => 50.0, 'origins' => ['lazy'], 'entities' => [FakeLabel::class], 'queries' => [2, 3]],
            ['caller' => 'TaskController.php:18', 'count' => 1, 'duration' => 5.0, 'origins' => ['findBy'], 'entities' => [FakeTask::class], 'queries' => [1]],
            ['caller' => null, 'count' => 1, 'duration' => 1.0, 'origins' => ['introspection'], 'entities' => [], 'queries' => [4]],
        ], $this->collector->getQueriesByCaller());
    }

    #[Test]
    public function itExportsTheProfileOfTheQueries(): void
    {
        $collector = new GraphqlOrmDataCollector(slowQueryThreshold: 50.0, maxQueries: 10, repeatedQueryThreshold: 2);

        foreach ([[100.0, 60.0, 1], [100.01, 20.0, 2]] as [$startedAt, $duration, $id]) {
            $trace = self::traceOf('query { task(id: ' . $id . ') { id } }');
            $trace->startedAt = $startedAt;
            $trace->duration = $duration;
            $trace->hydrationDuration = 1.5;
            $trace->origin = 'find';
            $trace->entityClass = FakeTask::class;
            $trace->manager = 'default';
            $trace->caller = ['file' => 'TaskController.php', 'line' => 18, 'class' => null, 'function' => null];
            $trace->responseSize = 120;
            $trace->hydratedEntities = 1;
            $trace->ast = ['operation' => 'query'];
            $collector->addQuery($trace);
        }

        $collector->collect(new Request(server: ['REQUEST_TIME_FLOAT' => 99.9]), new Response());
        $export = $collector->getExport();

        self::assertSame('graphql-orm-profile', $export['format']);
        self::assertSame(1, $export['version']);
        self::assertEqualsWithDelta(
            ['queries' => 2, 'sent' => 2, 'distinct' => 1, 'waited_ms' => 60.0, 'summed_ms' => 80.0, 'hydration_ms' => 3.0, 'errors' => 0, 'slow' => 1, 'entities_hydrated' => 2, 'response_bytes' => 240],
            $export['summary'],
            0.001,
        );
        self::assertSame(['slow_query_ms' => 50.0, 'max_queries' => 10, 'max_query_time_ms' => null, 'repeated_queries' => 2], $export['thresholds']);
        self::assertCount(2, $export['queries']);
        self::assertEqualsWithDelta([
            'number' => 1,
            'manager' => 'default',
            'origin' => 'find',
            'entity' => FakeTask::class,
            'root_field' => 'task',
            'graphql' => 'query { task(id: 1) { id } }',
            'variables' => [],
            'values_masked' => false,
            'caller' => 'TaskController.php:18',
            'started_ms' => 100.0,
            'duration_ms' => 60.0,
            'hydration_ms' => 1.5,
            'response_bytes' => 120,
            'entities_hydrated' => 1,
            'result_cache' => null,
            'slow' => true,
            'errors' => [],
            'sub_request' => null,
            'after_response' => false,
        ], $export['queries'][0], 0.001);
        self::assertSame([['shape' => 'query { task(id: ***) { id } }', 'count' => 2, 'queries' => [1, 2]]], $export['findings']['repeated_queries']);
        self::assertSame([['class' => FakeTask::class, 'count' => 2, 'queries' => [1, 2]]], $export['findings']['cache_candidates']);
        self::assertSame([], $export['findings']['lazy_loads']);
    }

    #[Test]
    public function itSuggestsTheSecondLevelCacheForAnEntityReadAgainAndAgain(): void
    {
        foreach ([['find', FakeLabel::class], ['lazy', FakeLabel::class], ['find', FakeLabel::class], ['findBy', FakeTask::class], ['find', CachedTask::class], ['find', CachedTask::class], ['find', CachedTask::class]] as [$origin, $class]) {
            $trace = self::traceOf('query { labels { code } }');
            $trace->origin = $origin;
            $trace->entityClass = $class;
            $this->collector->addQuery($trace);
        }

        self::assertSame([['class' => FakeLabel::class, 'count' => 3, 'queries' => [1, 2, 3]]], $this->collector->getCacheCandidates());
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
    public function itKnowsTheDisabledCachesInTheStoredProfile(): void
    {
        $collector = new GraphqlOrmDataCollector(configuration: ['result_cache' => ['pool' => null], 'second_level_cache' => ['enabled' => false]]);
        $collector->collect(new Request(), new Response());

        $restored = unserialize(serialize($collector));

        self::assertInstanceOf(GraphqlOrmDataCollector::class, $restored);
        self::assertNull($restored->getResultCacheUsage());
        self::assertNull($restored->getSecondLevelCacheUsage());
    }

    #[Test]
    public function itReportsAQuerySlowByTheThresholdOfItsManager(): void
    {
        $collector = new GraphqlOrmDataCollector(slowQueryThreshold: 500.0);
        $trace = self::trace(duration: 150.0, errors: null);
        $trace->slowQueryThreshold = 100.0;

        $collector->addQuery($trace);

        self::assertTrue($collector->getQueries()[0]['slow'] ?? null);
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

    #[Test]
    public function itDescribesTheMappedEntitiesAndTheQueriesReadingThem(): void
    {
        $collector = new GraphqlOrmDataCollector(metadataFactory: new GraphqlEntityMetadataFactory(), entityClasses: [ChainA::class]);
        $trace = self::traceOf('query { chainAs { id } }');
        $trace->entityClass = ChainA::class;
        $collector->addQuery($trace);

        $collector->collect(new Request(), new Response());

        $entity = $collector->getEntities()[0];
        self::assertSame([ChainA::class, 'chainAs', 1, 'id'], [$entity['class'], $entity['name'], $entity['queries'], $entity['identifier']]);
        self::assertIsArray($entity['fields']);
        self::assertSame(
            ['property' => 'next', 'field' => 'next', 'relation' => ChainB::class, 'collection' => false, 'fetch' => 'Eager', 'converter' => null, 'converter_source' => null, 'embedded' => null],
            $entity['fields'][2] ?? null,
        );
    }

    #[Test]
    public function itDescribesTheTypeConverterOfEachField(): void
    {
        $collector = new GraphqlOrmDataCollector(
            metadataFactory: new GraphqlEntityMetadataFactory(),
            entityClasses: [Product::class],
            typeConverters: new TypeConverterRegistry([new MoneyConverter()]),
        );

        $collector->collect(new Request(), new Response());

        $fields = $collector->getEntities()[0]['fields'] ?? null;
        self::assertIsArray($fields);
        $converters = array_column($fields, 'converter', 'property');
        $sources = array_column($fields, 'converter_source', 'property');

        self::assertSame([MoneyConverter::class, 'type'], [$converters['price'], $sources['price']]);
        self::assertSame([DecimalMoneyConverter::class, 'field'], [$converters['discount'], $sources['discount']]);
        self::assertNull($converters['contact']);
        self::assertNull($converters['name']);
    }

    #[Test]
    public function itDescribesTheEmbeddedValueObjects(): void
    {
        $collector = new GraphqlOrmDataCollector(metadataFactory: new GraphqlEntityMetadataFactory(), entityClasses: [Order::class]);

        $collector->collect(new Request(), new Response());

        $fields = $collector->getEntities()[0]['fields'] ?? null;
        self::assertIsArray($fields);
        $embedded = array_column($fields, 'embedded', 'property');
        self::assertSame(['class' => Money::class, 'fields' => ['price.amount', 'price.currency']], $embedded['price']);
        self::assertSame(['class' => Money::class, 'fields' => ['total_amount', 'total_currency']], $embedded['total']);
        self::assertNull($embedded['id']);
    }

    #[Test]
    public function itKeepsTheDetailOfTheFirstQueriesOnly(): void
    {
        $limit = $this->collector->getDetailedQueryLimit();

        for ($index = 0; $index <= $limit; ++$index) {
            $trace = self::trace(duration: 2.0, errors: null);
            $trace->variables = ['id' => $index];
            $this->collector->addQuery($trace);
        }

        $queries = $this->collector->getQueries();
        self::assertCount($limit + 1, $queries);
        self::assertTrue($queries[$limit - 1]['detailed']);
        self::assertSame(['id' => $limit - 1], $queries[$limit - 1]['variables']);
        self::assertFalse($queries[$limit]['detailed']);
        self::assertSame('', $queries[$limit]['graphql']);
        self::assertSame([], $queries[$limit]['variables']);
        self::assertSame('query { tasks { id } }', $queries[$limit]['shape']);
        self::assertEqualsWithDelta(($limit + 1) * 2.0, $this->collector->getTotalDuration(), 0.001);
    }

    #[Test]
    public function itMarksAfterTheResponseOnlyTheQueriesSentOnceTheMainRequestIsCollected(): void
    {
        $requests = new RequestStack();
        $main = Request::create('/tasks');
        $requests->push($main);
        $collector = new GraphqlOrmDataCollector(requestStack: $requests);
        $sidebar = Request::create('/_fragment/sidebar');
        $requests->push($sidebar);
        $collector->collect($sidebar, new Response());
        $requests->pop();

        $collector->addQuery(self::traceOf('query { tasks { id } }'));
        $collector->collect($main, new Response());
        $collector->addQuery(self::traceOf('query { users { id } }'));

        self::assertSame([false, true], array_column($collector->getQueries(), 'after_response'));
    }

    #[Test]
    public function itTellsTheSubRequestSendingAQuery(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/tasks'));
        $collector = new GraphqlOrmDataCollector(requestStack: $requests);
        $requests->push(Request::create('/_fragment/sidebar'));
        $collector->addQuery(self::traceOf('query { labels { code } }'));
        $requests->pop();
        $collector->addQuery(self::traceOf('query { tasks { id } }'));

        self::assertSame(['/_fragment/sidebar', null], array_column($collector->getQueries(), 'sub_request'));
    }

    #[Test]
    public function itDescribesTheEntitiesOfTheQueriesSentAfterTheResponse(): void
    {
        $collector = new GraphqlOrmDataCollector(metadataFactory: new GraphqlEntityMetadataFactory());
        $collector->collect(new Request(), new Response());
        $trace = self::traceOf('query { tasks { id } }');
        $trace->entityClass = FakeTask::class;
        $collector->addQuery($trace);

        $collector->lateCollect();

        self::assertSame([FakeTask::class], array_column($collector->getEntities(), 'class'));
    }

    #[Test]
    public function itMarksTheQueriesSentAfterTheResponse(): void
    {
        $this->collector->addQuery(self::traceOf('query { tasks { id } }'));
        $this->collector->collect(new Request(), new Response());
        $this->collector->addQuery(self::traceOf('query { users { id } }'));

        self::assertSame([false, true], array_column($this->collector->getQueries(), 'after_response'));
    }

    #[Test]
    public function itAddsTheEntitiesReadByTheQueriesToThoseOfTheMappingDirectory(): void
    {
        $collector = new GraphqlOrmDataCollector(metadataFactory: new GraphqlEntityMetadataFactory());
        $trace = self::traceOf('query { tasks { id } }');
        $trace->entityClass = FakeTask::class;
        $collector->addQuery($trace);

        $collector->collect(new Request(), new Response());

        self::assertSame([FakeTask::class], array_column($collector->getEntities(), 'class'));
    }

    #[Test]
    public function itSumsUpTheEntitiesLoadedOnFirstAccessByRelation(): void
    {
        foreach ([1, 2, 3] as $id) {
            $this->collector->ghostCreated('App\\Entity\\User', $id, 'App\\Entity\\Task::$assignee');
        }
        $this->collector->addQuery(self::traceOf('query { tasks { id } }'));
        $this->collector->addQuery(self::traceOf('query { users(filter: {}) { id name } }'));

        $this->collector->ghostsLoaded('App\\Entity\\User', [1, 2]);

        self::assertSame([[
            'relation' => 'App\\Entity\\Task::$assignee',
            'class' => 'App\\Entity\\User',
            'created' => 3,
            'loaded' => 2,
            'unread' => 1,
            'queries' => [2],
        ]], $this->collector->getLazyLoading());
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
