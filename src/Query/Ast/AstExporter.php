<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Ast;

/**
 * Exports the AST of a query as arrays, for the trace shown by the profiler: what json_encode() then json_decode()
 * would give, without writing and parsing JSON on every query.
 *
 * @internal
 */
final class AstExporter
{
    /**
     * @return array<string|int, mixed>
     */
    public static function export(QueryNode $query): array
    {
        $exported = self::value($query);

        return \is_array($exported) ? $exported : [];
    }

    private static function value(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \JsonSerializable => self::value($value->jsonSerialize()),
            $value instanceof \BackedEnum => $value->value,
            // As json_encode(): a date gives its date, timezone_type and timezone, which get_object_vars() does not see.
            $value instanceof \DateTimeInterface => (array) $value,
            // As json_encode(): the public properties of another object.
            \is_object($value) => array_map(self::value(...), get_object_vars($value)),
            \is_array($value) => array_map(self::value(...), $value),
            default => $value,
        };
    }
}
