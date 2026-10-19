<?php

declare(strict_types=1);

namespace GraphqlOrm\DataCollector;

/**
 * Reads a query as a profile keeps it: a profile written by an older version may lack a value, or hold another type.
 *
 * @internal
 */
final class ProfiledQuery
{
    /**
     * @param array<string, mixed> $query
     *
     * @return int|float the milliseconds of this key, 0 when unknown
     */
    public static function milliseconds(array $query, string $key): int|float
    {
        $value = $query[$key] ?? 0;

        return \is_int($value) || \is_float($value) ? $value : 0;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return string|null the file and line sending the query, null for a query of the bundle itself
     */
    public static function caller(array $query): ?string
    {
        $caller = $query['caller'] ?? null;

        return \is_array($caller) && \is_string($caller['file'] ?? null)
            ? $caller['file'] . (\is_int($caller['line'] ?? null) ? ':' . $caller['line'] : '')
            : null;
    }
}
