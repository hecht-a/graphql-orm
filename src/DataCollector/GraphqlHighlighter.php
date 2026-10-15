<?php

declare(strict_types=1);

namespace GraphqlOrm\DataCollector;

use GraphqlOrm\Logging\QueryRedactor;

/**
 * Turns a GraphQL query into HTML whose tokens are wrapped in spans, one class per kind, for the profiler. Everything
 * else is escaped as is, so that the layout of the query is kept.
 *
 * @internal
 */
final class GraphqlHighlighter
{
    /**
     * Block strings, strings, comments, variables, masked values, names and numbers.
     */
    private const TOKEN_PATTERN = '/"""(?:\\\\"""|[\s\S])*?"""|"(?:[^"\\\\\n]|\\\\.)*"|#[^\n]*|\$[_A-Za-z][_0-9A-Za-z]*|\*\*\*|[_A-Za-z][_0-9A-Za-z]*|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/';

    private const KEYWORDS = ['query', 'mutation', 'subscription', 'fragment', 'on', 'true', 'false', 'null'];

    private const MASKED_STRING = '"' . QueryRedactor::MASK . '"';

    public function highlight(string $graphql): string
    {
        preg_match_all(self::TOKEN_PATTERN, $graphql, $matches, PREG_OFFSET_CAPTURE);

        $html = '';
        $position = 0;

        foreach ($matches[0] as [$token, $offset]) {
            $html .= self::escape(substr($graphql, $position, $offset - $position));
            $kind = self::kind($token, substr($graphql, $offset + \strlen($token)));
            $html .= \sprintf('<span class="graphql-orm-hl-%s">%s</span>', $kind, self::escape($token));
            $position = $offset + \strlen($token);
        }

        return $html . self::escape(substr($graphql, $position));
    }

    /**
     * @param string $rest the query after the token, telling an argument (followed by a colon) from a field
     */
    private static function kind(string $token, string $rest): string
    {
        return match (true) {
            $token === QueryRedactor::MASK, $token === self::MASKED_STRING => 'masked',
            str_starts_with($token, '"') => 'string',
            str_starts_with($token, '#') => 'comment',
            str_starts_with($token, '$') => 'variable',
            $token[0] === '-' || ctype_digit($token[0]) => 'number',
            \in_array($token, self::KEYWORDS, true) => 'keyword',
            str_starts_with(ltrim($rest), ':') => 'argument',
            ctype_upper($token[0]) => 'type',
            default => 'field',
        };
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
