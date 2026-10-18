<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\DataCollector;

use GraphqlOrm\Cache\CachePoolDescriber;
use GraphqlOrm\Converter\TypeConverterRegistry;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\GraphqlQueryTrace;
use GraphqlOrm\Tests\Fixtures\Converter\MoneyConverter;
use GraphqlOrm\Tests\Fixtures\Converter\Product;
use GraphqlOrm\Tests\Fixtures\Dto\Task;
use GraphqlOrm\Tests\Fixtures\Dto\TaskSummary;
use GraphqlOrm\Tests\Fixtures\Embedded\Order;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeLabel;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Twig\GraphqlOrmTwigExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * Renders the profiler template of the bundle, the WebProfiler layout being replaced by one only printing the blocks.
 */
final class ProfilerTemplateTest extends TestCase
{
    #[Test]
    public function itRendersTheQueries(): void
    {
        $collector = $this->collector(['query { tasks { id } }', 'query { users { id } }']);

        $html = $this->render($collector);

        self::assertStringContainsString('id="graphql-orm-query-2"', $html);
        self::assertStringContainsString('users', strip_tags($html));
        self::assertStringNotContainsString('Repeated queries', $html);
    }

    #[Test]
    public function itSummarizesTheRequestInMetrics(): void
    {
        $collector = new GraphqlOrmDataCollector();

        foreach ([[120.5, 4, 2048], [750, 3, 1024]] as [$duration, $entities, $bytes]) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = 'query { tasks { id } }';
            $trace->duration = $duration;
            $trace->hydratedEntities = $entities;
            $trace->responseSize = $bytes;
            $collector->addQuery($trace);
        }

        $text = self::text($this->render($collector));

        self::assertStringContainsString('870.5 ms Time in GraphQL', $text);
        self::assertStringContainsString('7 Entities hydrated', $text);
        self::assertStringContainsString('3.0 KB Received', $text);
    }

    #[Test]
    public function itShowsTheDetailOfEachQueryInTabs(): void
    {
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $trace->variables = ['first' => 10];
        $collector = new GraphqlOrmDataCollector();
        $collector->addQuery($trace);

        $html = $this->render($collector);

        foreach (['graphql', 'variables', 'hydration'] as $pane) {
            self::assertStringContainsString(\sprintf('data-graphql-orm-pane="%s"', $pane), $html);
        }
    }

    #[Test]
    public function itFiltersTheSlowQueriesAndTheQueriesInError(): void
    {
        $collector = new GraphqlOrmDataCollector(slowQueryThreshold: 500.0);

        foreach ([[750.0, null], [10.0, [['message' => 'Forbidden']]]] as [$duration, $errors]) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = 'query { tasks { id } }';
            $trace->duration = $duration;
            $trace->errors = $errors;
            $collector->addQuery($trace);
        }

        $html = $this->render($collector);

        self::assertStringContainsString('data-graphql-orm-filter="slow"', $html);
        self::assertStringContainsString('data-graphql-orm-filter="error"', $html);
        self::assertStringContainsString('data-graphql-orm-status="slow"', $html);
        self::assertStringContainsString('data-graphql-orm-status="error"', $html);
    }

    #[Test]
    public function itCopiesAQueryAsACurlCommand(): void
    {
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $trace->endpoint = 'https://graphql.example.com/graphql';
        $collector = new GraphqlOrmDataCollector();
        $collector->addQuery($trace);

        $html = $this->render($collector);

        self::assertStringContainsString('Copy as cURL', $html);
        self::assertStringContainsString('data-copy="curl&#x20;-X&#x20;POST&#x20;&#x27;https&#x3A;&#x2F;&#x2F;graphql.example.com&#x2F;graphql&#x27;', $html);
    }

    #[Test]
    public function itCopiesWithoutTheClipboardApiOutsideASecureContext(): void
    {
        $html = $this->render($this->collector(['query { tasks { id } }']));

        self::assertStringContainsString('navigator.clipboard && window.isSecureContext', $html);
        self::assertStringContainsString('document.execCommand("copy")', $html);
    }

    #[Test]
    public function itHighlightsTheSlowQueries(): void
    {
        $collector = new GraphqlOrmDataCollector(slowQueryThreshold: 500.0);

        foreach ([120.0, 750.0] as $duration) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = 'query { tasks { id } }';
            $trace->duration = $duration;
            $collector->addQuery($trace);
        }

        $html = $this->render($collector);

        self::assertStringContainsString('Slow', $html);
        self::assertStringContainsString('over 500 ms', $html);
        self::assertSame(1, substr_count($html, 'graphql-orm-slow-badge'));
    }

    #[Test]
    public function itDoesNotMentionSlowQueriesWhenThereAreNone(): void
    {
        $html = $this->render($this->collector(['query { tasks { id } }']));

        self::assertStringNotContainsString('graphql-orm-slow-badge', $html);
        self::assertStringNotContainsString('<b>Slow</b>', $html);
    }

    #[Test]
    public function itRendersTheAstOfAQuery(): void
    {
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $trace->ast = ['operation' => 'query', 'fields' => [[
            'name' => 'tasks',
            'arguments' => [],
            'selectionSet' => ['fields' => [['name' => 'id', 'arguments' => [], 'selectionSet' => null, 'isCollection' => false]]],
            'isCollection' => false,
        ]]];
        $collector = new GraphqlOrmDataCollector();
        $collector->addQuery($trace);

        $html = $this->render($collector);

        self::assertStringContainsString('ast-root', $html);
        self::assertStringContainsString('tasks', strip_tags($html));
    }

    #[Test]
    public function itRendersTheArgumentsOfTheAstAsJson(): void
    {
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks(where: {status: {_eq: "done"}}, limit: 10, distinct: false) { id } }';
        $trace->ast = ['operation' => 'query', 'fields' => [[
            'name' => 'tasks',
            'arguments' => ['where' => ['status' => ['_eq' => 'done']], 'limit' => 10, 'distinct' => false],
            'selectionSet' => ['fields' => [['name' => 'id', 'arguments' => [], 'selectionSet' => null, 'isCollection' => false]]],
            'isCollection' => false,
        ]]];
        $collector = new GraphqlOrmDataCollector();
        $collector->addQuery($trace);

        $text = html_entity_decode(strip_tags($this->render($collector)));

        self::assertStringContainsString('where={"status":{"_eq":"done"}}', $text);
        self::assertStringContainsString('limit=10', $text);
        self::assertStringContainsString('distinct=false', $text);
    }

    #[Test]
    public function itShowsTheOriginOfEachQuery(): void
    {
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $trace->origin = 'findBy';
        $trace->entityClass = FakeTask::class;
        $collector = new GraphqlOrmDataCollector();
        $collector->addQuery($trace);

        $text = self::text($this->render($collector));

        self::assertStringContainsString('findBy', $text);
        self::assertStringContainsString('FakeTask', $text);
    }

    #[Test]
    public function itDrawsTheQueriesOnATimeline(): void
    {
        $collector = new GraphqlOrmDataCollector();

        foreach ([100.0, 100.5] as $startedAt) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = 'query { tasks { id } }';
            $trace->startedAt = $startedAt;
            $trace->duration = 250.0;
            $collector->addQuery($trace);
        }

        $html = $this->render($collector);

        self::assertStringContainsString('data-graphql-orm-tab="timeline"', $html);
        self::assertStringContainsString('left: 66.7%; width: 33.3%', $html);
    }

    #[Test]
    public function itTellsTheTimeWaitedForQueriesSentTogether(): void
    {
        $collector = new GraphqlOrmDataCollector();

        foreach ([100.0, 100.0, 100.0] as $startedAt) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = 'query { tasks { id } }';
            $trace->startedAt = $startedAt;
            $trace->duration = 100.0;
            $collector->addQuery($trace);
        }

        $text = self::text($this->render($collector));

        self::assertStringContainsString('100.0 ms Time in GraphQL 300.0 ms summed, queries sent together', $text);
        self::assertStringContainsString('Query time 100.00 ms', $text);
        self::assertStringContainsString('3 queries over 100 ms, 100.0 ms in GraphQL', $text);
    }

    #[Test]
    public function itAlertsBeyondTheQueriesAndTheTimeConfigured(): void
    {
        $collector = new GraphqlOrmDataCollector(maxQueries: 1, maxQueryTime: 100.0);

        foreach ([1, 2] as $id) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = 'query { task(id: ' . $id . ') { id } }';
            $trace->duration = 60.0;
            $collector->addQuery($trace);
        }

        $html = $this->render($collector);
        $text = self::text($html);

        self::assertMatchesRegularExpression('/<b>Queries<\/b>\s*<span class="sf-toolbar-status sf-toolbar-status-yellow" title="More than 1 queries \(profiler.max_queries\)">2</', $html);
        self::assertMatchesRegularExpression('/<b>Query time<\/b>\s*<span class="sf-toolbar-status sf-toolbar-status-yellow" title="More than 100 ms \(profiler.max_query_time\)">120.00 ms</', $html);
        self::assertStringContainsString('2 Queries more than 1 (profiler.max_queries)', $text);
        self::assertStringContainsString('120.0 ms Time in GraphQL more than 100 ms (profiler.max_query_time)', $text);
    }

    #[Test]
    public function itOffersToDownloadTheProfileAsJson(): void
    {
        $collector = $this->collector(['query { tasks { id } }']);

        $html = $this->render($collector);

        self::assertMatchesRegularExpression('/data-graphql-orm-export="graphql-orm-abc.json"\s+data-graphql-orm-json="[^"]*&quot;format&quot;:&quot;graphql-orm-profile&quot;/', $html);
        self::assertStringContainsString('Export JSON', self::text($html));
    }

    #[Test]
    public function itListsTheDetailedQueriesAndCountsTheOthers(): void
    {
        $collector = new GraphqlOrmDataCollector();

        for ($index = 0; $index < $collector->getDetailedQueryLimit() + 3; ++$index) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = 'query { tasks { id } }';
            $trace->duration = 1.0;
            $collector->addQuery($trace);
        }

        $html = $this->render($collector);

        self::assertSame($collector->getDetailedQueryLimit(), substr_count($html, 'class="graphql-orm-row'));
        self::assertStringContainsString('And 3 more queries, counted in the metrics: the profile keeps the detail of the first 500.', self::text($html));
    }

    #[Test]
    public function itTellsTheSubRequestOfAQuery(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/tasks'));
        $requests->push(Request::create('/_fragment/sidebar'));
        $collector = new GraphqlOrmDataCollector(requestStack: $requests);
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { labels { code } }';
        $trace->origin = 'findAll';
        $collector->addQuery($trace);

        self::assertStringContainsString('findAll sub-request /_fragment/sidebar', self::text($this->render($collector)));
    }

    #[Test]
    public function itOffersToReplayAQueryReadWithItsValues(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $collector->addQuery($trace);

        self::assertStringContainsString('data-graphql-orm-replay="/_graphql_orm/replay/abc/1"', $this->render($collector));
    }

    #[Test]
    public function itTellsWhyAQueryWhoseValuesAreMaskedIsNotReplayed(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $trace->redacted = true;
        $collector->addQuery($trace);
        $html = $this->render($collector);

        self::assertStringNotContainsString('data-graphql-orm-replay=', $html);
        self::assertStringContainsString('Replay: the values are masked, set redact_query_values: false', self::text($html));
    }

    #[Test]
    public function itDoesNotOfferToReplayAMutation(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'mutation { createTask(item: { title: "Task A" }) { id } }';
        $collector->addQuery($trace);
        $html = $this->render($collector);

        self::assertStringNotContainsString('data-graphql-orm-replay=', $html);
        self::assertStringNotContainsString('Replay:', self::text($html));
    }

    #[Test]
    public function itMarksTheQueriesSentAfterTheResponse(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $collector->collect(new Request(), new Response());
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $trace->origin = 'findBy';
        $collector->addQuery($trace);

        self::assertStringContainsString('findBy after the response', self::text($this->render($collector)));
    }

    #[Test]
    public function itListsTheQueriesByCaller(): void
    {
        $collector = new GraphqlOrmDataCollector();
        foreach ([20.0, 30.0] as $duration) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = 'query { labels { code } }';
            $trace->duration = $duration;
            $trace->origin = 'lazy';
            $trace->entityClass = FakeLabel::class;
            $trace->caller = ['file' => 'templates/task/board.html.twig', 'line' => 7, 'class' => null, 'function' => null];
            $collector->addQuery($trace);
        }

        $html = $this->render($collector);

        self::assertStringContainsString('data-graphql-orm-tab="callers"', $html);
        self::assertStringContainsString('board.html.twig:7 2 50.0 ms lazy FakeLabel #1, #2', self::text($html));
    }

    #[Test]
    public function itSuggestsLoadingARelationLoadedOneByOneWithWith(): void
    {
        $collector = $this->collector(['query { tasks { id } }']);
        foreach ([1, 2, 3] as $id) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = \sprintf('query { users(id: %d) { id } }', $id);
            $trace->origin = 'lazy';
            $trace->lazyRelation = 'App\\Entity\\Task::$assignee';
            $collector->addQuery($trace);
        }

        $text = self::text($this->render($collector));

        self::assertStringContainsString("Load the relation with the query of its entities: ->with('assignee') on the query builder, or \$repository->with('assignee')->findBy(...)", $text);
    }

    #[Test]
    public function itSuggestsSelectingLessForASlowQuery(): void
    {
        $collector = new GraphqlOrmDataCollector(slowQueryThreshold: 100.0);
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $trace->origin = 'findBy';
        $trace->duration = 250.0;
        $collector->addQuery($trace);

        self::assertStringContainsString('Select only the fields used with select() or hydrateAs(), or keep the result with enableResultCache().', self::text($this->render($collector)));
    }

    #[Test]
    public function itSuggestsTheSecondLevelCacheForAnEntityReadAgainAndAgain(): void
    {
        $collector = new GraphqlOrmDataCollector();
        foreach ([1, 2, 3] as $id) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = \sprintf('query { labels(code: "%d") { code } }', $id);
            $trace->origin = 'find';
            $trace->entityClass = FakeLabel::class;
            $collector->addQuery($trace);
        }

        $text = self::text($this->render($collector));

        self::assertStringContainsString('FakeLabel read by 3 queries', $text);
        self::assertStringContainsString('mark it #[GraphqlOrm\\Cache] to keep it in the second-level cache', $text);
    }

    #[Test]
    public function itShowsTheTimeBeforeTheFirstQuery(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $trace->startedAt = 100.07;
        $trace->duration = 30.0;
        $collector->addQuery($trace);
        $collector->collect(new Request(server: ['REQUEST_TIME_FLOAT' => 100.0]), new Response());

        $html = $this->render($collector);

        self::assertStringContainsString('the first one sent 70.0 ms after the start of the request', self::text($html));
        self::assertStringContainsString('class="graphql-orm-timeline-before" style="width: 70%"', $html);
        self::assertStringContainsString('Before the first query', self::text($html));
    }

    #[Test]
    public function itDetailsEachQueryOfTheTimeline(): void
    {
        $collector = new GraphqlOrmDataCollector(slowQueryThreshold: 200.0);
        $slow = new GraphqlQueryTrace();
        $slow->graphql = 'query { tasks { id } }';
        $slow->startedAt = 100.0;
        $slow->duration = 812.3;
        $slow->hydrationDuration = 4.0;
        $slow->origin = 'find';
        $slow->entityClass = Task::class;
        $collector->addQuery($slow);
        $lazy = new GraphqlQueryTrace();
        $lazy->graphql = 'query { users { id } }';
        $lazy->startedAt = 100.9;
        $lazy->duration = 6.5;
        $lazy->origin = 'lazy';
        $collector->addQuery($lazy);

        $html = $this->render($collector);
        $text = self::text($html);

        self::assertStringContainsString('2 queries over 907 ms, 818.8 ms in GraphQL', $text);
        self::assertStringContainsString('#1 find Task 816.3 ms', $text);
        self::assertStringContainsString('#2 lazy 6.5 ms', $text);
        self::assertStringContainsString('0 ms 250 ms 500 ms 750 ms', $text);
        self::assertStringContainsString('graphql-orm-timeline-bar graphql-orm-fill-hydration', $html);
        self::assertStringContainsString('Network Hydration Slow Error Lazy load Aggregate Introspection', $text);
    }

    #[Test]
    public function itWarnsAboutARelationLoadedOneEntityAtATime(): void
    {
        $collector = new GraphqlOrmDataCollector();

        foreach ([1, 2, 3] as $id) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = \sprintf('query { users(id: %d) { id name } }', $id);
            $trace->origin = 'lazy';
            $trace->lazyRelation = 'App\\Entity\\Task::$assignee';
            $collector->addQuery($trace);
        }

        $html = $this->render($collector);
        $text = self::text($html);

        self::assertStringContainsString('Task::$assignee loaded 3 times', $text);
        self::assertStringContainsString('data-graphql-orm-tab="findings"', $html);
        self::assertStringNotContainsString('Repeated queries', $html);
    }

    #[Test]
    public function itListsTheEntitiesServedByTheIdentityMap(): void
    {
        $collector = $this->collector(['query { tasks { id } }']);
        $collector->addIdentityMapHit(FakeTask::class, 12, 'src/Controller/TaskController.php:51');

        $html = $this->render($collector);
        $text = self::text($html);

        self::assertStringContainsString('1 Served by identity map', $text);
        self::assertStringContainsString('data-graphql-orm-tab="identity-map"', $html);
        self::assertStringContainsString('FakeTask', $text);
        self::assertStringContainsString('TaskController.php:51', $text);
    }

    #[Test]
    public function itShowsTheConfigurationAndTheSchemaValidation(): void
    {
        $collector = new GraphqlOrmDataCollector(configuration: ['dialect' => 'GraphqlOrm\\Dialect\\DataApiBuilderDialect', 'max_depth' => 1, 'identity_map' => true]);
        $collector->collect(new Request(), new Response());
        $collector->setSchemaValidation('warning', ['Report::$margin is a Float in the schema'], null);
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $trace->responsePreview = '{"data": {"tasks": []}}';
        $collector->addQuery($trace);

        $html = $this->render($collector);
        $text = self::text($html);

        self::assertStringContainsString('data-graphql-orm-tab="configuration"', $html);
        self::assertStringContainsString('max_depth 1', $text);
        self::assertStringContainsString('Report::$margin is a Float in the schema', $text);
        self::assertStringContainsString('data-graphql-orm-pane="response"', $html);
        self::assertStringContainsString('{"data": {"tasks": []}}', html_entity_decode($html));
    }

    #[Test]
    public function itRendersAProfileReadFromTheStorage(): void
    {
        $collector = new GraphqlOrmDataCollector(slowQueryThreshold: 500.0, configuration: ['max_depth' => 1]);
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $trace->duration = 750.0;
        $collector->addQuery($trace);
        $collector->collect(new Request(), new Response());

        $restored = unserialize(serialize($collector));
        self::assertInstanceOf(GraphqlOrmDataCollector::class, $restored);

        self::assertStringContainsString('Slow (≥ 500 ms)', self::text($this->render($restored)));
    }

    #[Test]
    public function itTellsTheNetworkFromTheHydration(): void
    {
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $trace->duration = 30.0;
        $trace->hydrationDuration = 10.0;
        $collector = new GraphqlOrmDataCollector();
        $collector->addQuery($trace);

        $html = $this->render($collector);
        $text = self::text($html);

        self::assertStringContainsString('10.0 ms Hydration', $text);
        self::assertStringContainsString('30.0 ms + 10.0 ms', $text);
        self::assertStringContainsString('graphql-orm-fill-hydration', $html);
    }

    #[Test]
    public function itHighlightsTheGraphqlQueries(): void
    {
        $html = $this->render($this->collector(['query { tasks(first: 10) { id } }']));

        self::assertStringContainsString('<span class="graphql-orm-hl-keyword">query</span>', $html);
        self::assertStringContainsString('<span class="graphql-orm-hl-argument">first</span>', $html);
    }

    #[Test]
    public function itSuggestsTheFindByReplacingAFindInALoop(): void
    {
        $collector = new GraphqlOrmDataCollector();

        foreach ([3, 7, 12] as $id) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = \sprintf('query { tasks(id: %d) { id } }', $id);
            $trace->origin = 'find';
            $trace->entityClass = FakeTask::class;
            $trace->identifierProperty = 'id';
            $collector->addQuery($trace);
        }

        $text = self::text($this->render($collector));

        self::assertStringContainsString("Each one is a find() of a FakeTask: load them at once with \$repository->findBy(['id' => [/* the 3 identifiers */]])", $text);
    }

    #[Test]
    public function itListsTheMappedEntities(): void
    {
        $collector = new GraphqlOrmDataCollector(metadataFactory: new GraphqlEntityMetadataFactory(), entityClasses: [FakeTask::class]);
        $collector->addQuery((function (): GraphqlQueryTrace {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = 'query { tasks { id } }';

            return $trace;
        })());
        $collector->collect(new Request(), new Response());

        $html = $this->render($collector);
        $text = self::text($html);

        self::assertStringContainsString('data-graphql-orm-tab="entities"', $html);
        self::assertStringContainsString('FakeTask', $text);
        self::assertStringContainsString('title title', $text);
    }

    #[Test]
    public function itShowsTheTypeConverterOfEachField(): void
    {
        $collector = new GraphqlOrmDataCollector(
            metadataFactory: new GraphqlEntityMetadataFactory(),
            entityClasses: [Product::class],
            typeConverters: new TypeConverterRegistry([new MoneyConverter()]),
        );
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { products { id } }';
        $collector->addQuery($trace);
        $collector->collect(new Request(), new Response());

        $text = self::text($this->render($collector));

        self::assertStringContainsString('price price - - MoneyConverter by type', $text);
        self::assertStringContainsString('discount discount - - DecimalMoneyConverter by #[GraphqlOrm\\Field]', $text);
    }

    #[Test]
    public function itShowsTheEmbeddedValueObjectsOfAnEntity(): void
    {
        $collector = new GraphqlOrmDataCollector(metadataFactory: new GraphqlEntityMetadataFactory(), entityClasses: [Order::class]);
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { orders { id } }';
        $collector->addQuery($trace);
        $collector->collect(new Request(), new Response());

        $text = self::text($this->render($collector));

        self::assertStringContainsString('price price Money embedded, price.amount, price.currency', $text);
        self::assertStringContainsString('total total Money embedded, total_amount, total_currency', $text);
    }

    #[Test]
    public function itSumsUpTheLazyLoadingInATab(): void
    {
        $collector = $this->collector(['query { tasks { id } }', 'query { users { id } }']);
        foreach ([1, 2, 3] as $id) {
            $collector->ghostCreated('App\\Entity\\User', $id, 'App\\Entity\\Task::$assignee');
        }
        $collector->ghostsLoaded('App\\Entity\\User', [1, 2]);

        $html = $this->render($collector);
        $text = self::text($html);

        self::assertStringContainsString('data-graphql-orm-tab="lazy-loading"', $html);
        self::assertStringContainsString('Task::$assignee User 3 2 1 #2', $text);
    }

    #[Test]
    public function itWarnsAboutTheRelationsLoadedWhileSerializing(): void
    {
        $collector = $this->collector(['query { tasks { id } }']);
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { users { id } }';
        $trace->origin = 'lazy';
        $trace->duringSerialization = true;
        $collector->addQuery($trace);
        $collector->entityNormalized('App\\Entity\\User', 'identifier');

        $html = $this->render($collector);
        $text = self::text($html);

        self::assertStringContainsString('1 relation loaded while serializing', $text);
        self::assertStringContainsString('data-graphql-orm-tab="serializer"', $html);
    }

    #[Test]
    public function itListsThePartialEntitiesInATab(): void
    {
        $collector = $this->collector([]);
        $collector->partialEntityHydrated('App\\Entity\\Label', ['name', 'position']);
        $collector->partialEntityHydrated('App\\Entity\\Label', ['name', 'position']);
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { labels { code } }';
        $collector->addQuery($trace);
        $collector->partialEntityRead('App\\Entity\\Label', ['position']);

        $html = $this->render($collector);
        $text = self::text($html);

        self::assertStringContainsString('data-graphql-orm-tab="partial-entities"', $html);
        self::assertStringContainsString('Label name, position 2 #1 1 (position)', $text);
    }

    #[Test]
    public function itTellsWhetherTheIntrospectionCachesServedTheSchema(): void
    {
        $collector = $this->collector(['query { tasks { id } }']);
        $collector->introspectionCacheLookup('argument_types', false, 0.2);
        $collector->introspectionCacheLookup('argument_types', false, 0.3);
        $collector->introspectionCacheLookup('countable_fields', true, 41.0);

        $text = self::text($this->render($collector));

        self::assertStringContainsString('Introspection caches', $text);
        self::assertStringContainsString('Argument types (variables) 2 served by the cache 0.5 ms', $text);
        self::assertStringContainsString('Countable fields (count()) 1 introspected 41.0 ms', $text);
    }

    #[Test]
    public function itDetailsTheCollectionsAndTheIndexOfAQuery(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { users { id tasks { id } } }';
        $trace->indexBy = 'id';
        $trace->collections = ['App\\Entity\\User::$tasks' => ['type' => 'Doctrine\\Common\\Collections\\ArrayCollection', 'collections' => 3, 'items' => 12]];
        $collector->addQuery($trace);

        $text = self::text($this->render($collector));

        self::assertStringContainsString('indexed by id', $text);
        self::assertStringContainsString('User::$tasks ArrayCollection 3 12', $text);
    }

    #[Test]
    public function itTellsTheQueriesHydratedAsDtos(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id title } }';
        $trace->entityClass = Task::class;
        $trace->hydratedAs = TaskSummary::class;
        $trace->hydratedCount = 8;
        $collector->addQuery($trace);

        $text = self::text($this->render($collector));

        self::assertStringContainsString('tasks Task DTO TaskSummary', $text);
        self::assertStringContainsString('8 DTOs', $text);
        self::assertStringContainsString('Hydrated as DTO GraphqlOrm\\Tests\\Fixtures\\Dto\\TaskSummary, created through its constructor', $text);
    }

    #[Test]
    public function itTellsTheRelationsNamedByWith(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $named = new GraphqlQueryTrace();
        $named->graphql = 'query { tasks { id } }';
        $named->with = ['assignee.company', 'label'];
        $collector->addQuery($named);
        $default = new GraphqlQueryTrace();
        $default->graphql = 'query { users { id } }';
        $collector->addQuery($default);

        $text = self::text($this->render($collector));

        self::assertStringContainsString('Relations loaded assignee.company, label, named by with()', $text);
        self::assertStringContainsString('Relations loaded up to max_depth', $text);
    }

    #[Test]
    public function itTellsTheQueriesHydratedAsEntities(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id title } }';
        $collector->addQuery($trace);

        self::assertStringContainsString('Hydrated as entities', self::text($this->render($collector)));
    }

    #[Test]
    public function itDescribesTheAutocompleteSearchesInATab(): void
    {
        $collector = $this->collector(['query { tasks { id } }']);
        $collector->autocompleteSearched('App\\Entity\\Task', ['title', 'code'], 'Fix', 2, 20);

        $html = $this->render($collector);
        $text = self::text($html);

        self::assertStringContainsString('data-graphql-orm-tab="autocomplete"', $html);
        self::assertStringContainsString('Task title, code *** 2 20', $text);
    }

    #[Test]
    public function itSumsUpTheSecondLevelCacheInATab(): void
    {
        $collector = $this->collector(['query { tasks { id } }']);
        foreach (['bug' => 'kept', 'docs' => 'miss'] as $code => $outcome) {
            $collector->secondLevelCacheEntry('App\\Entity\\Label', 'reference_data', $code, $outcome, null);
        }
        foreach (['bug', 'bug', 'feature'] as $code) {
            $collector->secondLevelCacheEntry('App\\Entity\\Label', 'reference_data', $code, 'hit', 'find');
        }

        $html = $this->render($collector);
        $text = self::text($html);

        self::assertStringContainsString('data-graphql-orm-tab="cache"', $html);
        self::assertStringContainsString('Cache 3/4', $text);
        self::assertStringContainsString('Label reference_data 3 1 1', $text);
    }

    #[Test]
    public function itMarksTheQueriesAnsweredByTheResultCache(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $trace->resultCache = 'hit';
        $collector->addQuery($trace);

        $text = self::text($this->render($collector));

        self::assertStringContainsString('result cache', $text);
        self::assertStringContainsString('1 Served by the result cache', $text);
    }

    #[Test]
    public function itSumsUpTheRequestInTheToolbarAsDoctrineDoes(): void
    {
        $collector = new GraphqlOrmDataCollector(configuration: ['result_cache' => ['pool' => 'cache.app'], 'second_level_cache' => ['enabled' => false]]);
        foreach (['query { users(id: 1) { id } }', 'query { users(id: 2) { id } }', 'query { tasks { id } }'] as $index => $graphql) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = $graphql;
            $trace->duration = 2.0;
            $trace->hydratedEntities = 2;
            $trace->resultCache = $index === 2 ? 'hit' : null;
            $collector->addQuery($trace);
        }
        $collector->setSchemaValidation('warning', ['tasks.title is missing'], null);

        $text = self::text($this->render($collector));

        self::assertStringContainsString('Queries 3 Sent to the API 2 Different statements 2 Query time 6.00 ms', $text);
        self::assertStringContainsString('Invalid entities 1', $text);
        self::assertStringContainsString('Entities hydrated 6', $text);
        self::assertStringContainsString('Result cache 1/1 hits', $text);
        self::assertStringContainsString('Second-level cache disabled', $text);
    }

    #[Test]
    public function itGivesTheHitsOfTheSecondLevelCacheInTheToolbar(): void
    {
        $collector = new GraphqlOrmDataCollector(configuration: ['result_cache' => ['pool' => null], 'second_level_cache' => ['enabled' => true]]);
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $collector->addQuery($trace);
        foreach (['hit', 'hit', 'hit', 'miss'] as $index => $outcome) {
            $collector->secondLevelCacheEntry('App\\Entity\\Label', null, $index, $outcome, 'find');
        }

        $text = self::text($this->render($collector));

        self::assertStringContainsString('Result cache disabled', $text);
        self::assertStringContainsString('Second-level cache 3/4 hits', $text);
    }

    #[Test]
    public function itDetailsTheResultCacheOfAQuery(): void
    {
        $collector = new GraphqlOrmDataCollector(configuration: ['result_cache' => ['pool' => 'cache.graphql']]);
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $trace->resultCache = 'hit';
        $trace->resultCacheKey = 'tasks.open';
        $trace->resultCacheLifetime = 300;
        $collector->addQuery($trace);

        $html = $this->render($collector);
        $text = self::text($html);

        self::assertStringContainsString('data-graphql-orm-subtab="cache"', $html);
        self::assertStringContainsString('Outcome hit: answered without being sent Key tasks.open Lifetime 300 s Pool cache.graphql', $text);
    }

    #[Test]
    public function itListsTheEntitiesOfTheSecondLevelCacheAndTheEvictions(): void
    {
        $collector = $this->collector(['query { labels { code } }']);
        $collector->secondLevelCacheEntry('App\\Entity\\Label', 'reference_data', 'bug', 'hit', 'lazy load of App\\Entity\\Task::$label');
        $collector->cacheEvicted('evictEntity', 'App\\Entity\\Label #bug', '/app/src/Controller/LabelController.php:42');

        $text = self::text($this->render($collector));

        self::assertStringContainsString('Label bug hit lazy load of Task::$label 1', $text);
        self::assertStringContainsString('evictEntity Label #bug LabelController.php:42', $text);
    }

    #[Test]
    public function itTellsTheQueriesSentToTheApiAndTheBackendsInTheToolbar(): void
    {
        $collector = new GraphqlOrmDataCollector(
            configuration: ['result_cache' => ['pool' => 'cache.redis'], 'second_level_cache' => ['enabled' => true]],
            cachePools: new CachePoolDescriber([
                'result cache' => ['id' => 'cache.redis', 'pool' => new ArrayAdapter(), 'lifetime' => null],
                'default region' => ['id' => 'cache.app', 'pool' => new ArrayAdapter(), 'lifetime' => 3600],
            ]),
        );
        foreach (['hit', 'miss', null] as $resultCache) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = 'query { tasks { id } }';
            $trace->resultCache = $resultCache;
            $collector->addQuery($trace);
        }
        $collector->collect(new Request(), new Response());

        $text = self::text($this->render($collector));

        self::assertStringContainsString('Queries 3 Sent to the API 2', $text);
        self::assertStringContainsString('Result cache 1/2 hits (Array)', $text);
        self::assertStringContainsString('Second-level cache 0/0 hits (Array)', $text);
    }

    #[Test]
    public function itDescribesThePoolsTheOperationsAndTheWarningsOfTheCaches(): void
    {
        $collector = new GraphqlOrmDataCollector(cachePools: new CachePoolDescriber([
            'reference_data' => ['id' => 'cache.array', 'pool' => new ArrayAdapter(), 'lifetime' => 86400],
        ]));
        $collector->secondLevelCacheEntry('App\\Entity\\Label', 'reference_data', 'bug', 'hit', 'find');
        $collector->cacheOperation('reference_data', 'read', 1, 0.42, 0);
        $collector->cacheOperation('reference_data', 'write', 2, 0.58, 2048);
        $trace = new GraphqlQueryTrace();
        $trace->graphql = 'query { tasks { id } }';
        $trace->origin = 'lazy';
        $trace->fromCachedEntity = true;
        $collector->addQuery($trace);
        $collector->collect(new Request(), new Response());

        $html = $this->render($collector);
        $text = self::text($html);

        self::assertStringContainsString('The region reference_data is kept in cache.array, an ArrayAdapter', $text);
        self::assertStringContainsString('reference_data cache.array Array 86400 s', $text);
        self::assertStringContainsString('reference_data 1 2 0 1.00 ms 2.0 KB', $text);
        self::assertStringContainsString('1 query sent by the relations of entities read from the cache: #1', $text);
        self::assertStringContainsString('from cached entity', $text);
    }

    #[Test]
    public function itShowsTheCachesOfARequestSendingNoQuery(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $collector->secondLevelCacheEntry('App\\Entity\\Label', null, 'bug', 'hit', 'find');
        $collector->cacheEvicted('evictEntity', 'App\\Entity\\Label #docs', '/app/src/Controller/LabelController.php:42');

        $html = $this->render($collector);
        $text = self::text($html);

        self::assertStringNotContainsString('No GraphQL queries were executed', $text);
        self::assertStringContainsString('data-graphql-orm-tab="cache"', $html);
        self::assertStringContainsString('evictEntity Label #docs LabelController.php:42', $text);
    }

    #[Test]
    public function itTellsTheApiOfEachQueryWhenThereAreSeveral(): void
    {
        $collector = new GraphqlOrmDataCollector(configuration: ['managers' => ['main', 'crm']]);
        foreach (['main' => 'query { tasks { id } }', 'crm' => 'query { contacts { id } }'] as $manager => $graphql) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = $graphql;
            $trace->manager = $manager;
            $collector->addQuery($trace);
        }

        $text = self::text($this->render($collector));

        self::assertStringContainsString('APIs main, crm', $text);
        self::assertStringContainsString('crm contacts', $text);
    }

    #[Test]
    public function itWarnsAboutRepeatedQueries(): void
    {
        $collector = $this->collector([
            'query { users(id: 1) { id } }',
            'query { users(id: 2) { id } }',
            'query { tasks { id } }',
            'query { users(id: 3) { id } }',
        ]);

        $html = $this->render($collector);

        self::assertStringContainsString('Repeated queries', $html);
        self::assertStringContainsString('3 times', $html);
        self::assertStringContainsString('query { users(id: ***) { id } }', self::text($html));
        self::assertStringContainsString('#1, #2, #4', strip_tags($html));
        self::assertStringContainsString('href="#graphql-orm-query-4"', $html);
    }

    /**
     * The visible text of the page, with its spaces collapsed.
     */
    private static function text(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
    }

    /**
     * @param string[] $queries
     */
    private function collector(array $queries): GraphqlOrmDataCollector
    {
        $collector = new GraphqlOrmDataCollector();

        foreach ($queries as $graphql) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = $graphql;
            $collector->addQuery($trace);
        }

        return $collector;
    }

    private function render(GraphqlOrmDataCollector $collector): string
    {
        $filesystem = new FilesystemLoader();
        $filesystem->addPath(\dirname(__DIR__, 2) . '/src/Resources/views', 'GraphqlOrm');
        $profiler = new ArrayLoader([
            '@WebProfiler/Profiler/layout.html.twig' => '{% block toolbar %}{% endblock %}{% block menu %}{% endblock %}{% block panel %}{% endblock %}',
            '@WebProfiler/Profiler/toolbar_item.html.twig' => '{{ icon }}{{ text }}',
        ]);

        $twig = new Environment(new ChainLoader([$profiler, $filesystem]), ['debug' => true, 'strict_variables' => true]);
        $twig->addExtension(new DebugExtension());
        $twig->addExtension(new GraphqlOrmTwigExtension());

        return $twig->render('@GraphqlOrm/collector/graphql_orm.html.twig', ['collector' => $collector, 'profiler_url' => '/_profiler/abc', 'token' => 'abc']);
    }
}
