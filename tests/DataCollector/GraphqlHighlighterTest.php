<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\DataCollector;

use GraphqlOrm\DataCollector\GraphqlHighlighter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GraphqlHighlighterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tokens(): iterable
    {
        yield 'keyword' => ['query', '<span class="graphql-orm-hl-keyword">query</span>'];
        yield 'variable' => ['$filter', '<span class="graphql-orm-hl-variable">$filter</span>'];
        yield 'string' => ['"done"', '<span class="graphql-orm-hl-string">&quot;done&quot;</span>'];
        yield 'number' => ['-12.5', '<span class="graphql-orm-hl-number">-12.5</span>'];
        yield 'masked value' => ['***', '<span class="graphql-orm-hl-masked">***</span>'];
        yield 'masked string' => ['"***"', '<span class="graphql-orm-hl-masked">&quot;***&quot;</span>'];
        yield 'comment' => ['# open tasks', '<span class="graphql-orm-hl-comment"># open tasks</span>'];
        yield 'type' => ['TaskFilterInput', '<span class="graphql-orm-hl-type">TaskFilterInput</span>'];
        yield 'field' => ['tasks', '<span class="graphql-orm-hl-field">tasks</span>'];
    }

    #[Test]
    #[DataProvider('tokens')]
    public function itHighlightsEachKindOfToken(string $graphql, string $html): void
    {
        self::assertSame($html, (new GraphqlHighlighter())->highlight($graphql));
    }

    #[Test]
    public function itTellsTheArgumentsFromTheFieldsAndKeepsTheLayout(): void
    {
        $html = (new GraphqlHighlighter())->highlight("query {\n  tasks(first: 10) { id }\n}");

        self::assertSame(
            "<span class=\"graphql-orm-hl-keyword\">query</span> {\n"
            . "  <span class=\"graphql-orm-hl-field\">tasks</span>(<span class=\"graphql-orm-hl-argument\">first</span>: <span class=\"graphql-orm-hl-number\">10</span>) { <span class=\"graphql-orm-hl-field\">id</span> }\n"
            . '}',
            $html,
        );
    }

    #[Test]
    public function itEscapesWhatItDoesNotHighlight(): void
    {
        $html = (new GraphqlHighlighter())->highlight('{ a } <script>');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;', $html);
    }
}
