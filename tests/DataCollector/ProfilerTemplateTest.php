<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\DataCollector;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Query\GraphqlQueryTrace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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

        self::assertStringContainsString('Query #2', $html);
        self::assertStringNotContainsString('Repeated queries', $html);
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
        self::assertStringContainsString('query { users(id: ***) { id } }', $html);
        self::assertStringContainsString('#1, #2, #4', strip_tags($html));
        self::assertStringContainsString('href="#graphql-orm-query-4"', $html);
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

        return $twig->render('@GraphqlOrm/collector/graphql_orm.html.twig', ['collector' => $collector, 'profiler_url' => '/_profiler/abc']);
    }
}
