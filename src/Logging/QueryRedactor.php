<?php

declare(strict_types=1);

namespace GraphqlOrm\Logging;

/**
 * Masks the values of a GraphQL query before it is logged or shown in the profiler, as they may hold personal data.
 * Names, enum values, true/false/null and variable references are kept so the query stays readable.
 *
 * @internal
 */
final class QueryRedactor
{
    public const MASK = '***';

    private const ARGUMENTS_KEY = 'arguments';

    /**
     * Block strings, strings, comments, names (with an optional `$` for variables) and numbers. Names are matched
     * before numbers so that digits inside a name are never mistaken for a value.
     */
    private const TOKEN_PATTERN = '/"""(?:\\\\"""|[\s\S])*?"""|"(?:[^"\\\\\n]|\\\\.)*"|#[^\n]*|\$?[_A-Za-z][_0-9A-Za-z]*|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/';

    public function redactQuery(string $query): string
    {
        return (string) preg_replace_callback(
            self::TOKEN_PATTERN,
            static fn (array $match): string => match (true) {
                str_starts_with($match[0], '"') => '"' . self::MASK . '"',
                str_starts_with($match[0], '-'), ctype_digit($match[0][0]) => self::MASK,
                default => $match[0],
            },
            $query,
        );
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    public function redactValues(array $values): array
    {
        return array_map(
            fn (mixed $value): mixed => match (true) {
                \is_array($value) => $this->redactValues($value),
                $value === null => null,
                default => self::MASK,
            },
            $values,
        );
    }

    /**
     * @param array<mixed> $ast
     *
     * @return array<mixed>
     */
    public function redactAst(array $ast): array
    {
        foreach ($ast as $key => $node) {
            if (!\is_array($node)) {
                continue;
            }

            $ast[$key] = $key === self::ARGUMENTS_KEY ? $this->redactValues($node) : $this->redactAst($node);
        }

        return $ast;
    }
}
