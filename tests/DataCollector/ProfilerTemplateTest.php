<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\DataCollector;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Query\GraphqlQueryTrace;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Twig\GraphqlOrmTwigExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
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

        return $twig->render('@GraphqlOrm/collector/graphql_orm.html.twig', ['collector' => $collector, 'profiler_url' => '/_profiler/abc']);
    }
}
