<?php

declare(strict_types=1);

namespace GraphqlOrm\Schema;

/**
 * Correspondence between GraphQL scalars and PHP types, shared by schema validation and entity generation. Besides
 * the standard scalars, it knows those of Data API Builder (Byte, Short, Long, Single, Decimal), of Hasura, named after
 * the PostgreSQL types (timestamptz, numeric, bigint...), and of PostGraphile (Datetime).
 *
 * @internal
 */
final class ScalarTypes
{
    private const DATE_SCALARS = ['DateTime', 'Date', 'Datetime', 'timestamptz', 'timestamp', 'date'];

    private const INT_SCALARS = ['Int', 'Byte', 'Short', 'Long', 'bigint', 'smallint', 'integer'];

    private const FLOAT_SCALARS = ['Float', 'Single', 'Decimal', 'numeric', 'float8', 'float4'];

    private const STRING_SCALARS = ['String', 'ID', 'Time', 'JSON', 'UUID', 'uuid', 'text'];

    /**
     * The PHP type generated for a scalar; scalars and enums not listed are generated as strings.
     */
    public static function phpTypeOf(?string $scalar): string
    {
        return match (true) {
            \in_array($scalar, self::INT_SCALARS, true) => 'int',
            \in_array($scalar, self::FLOAT_SCALARS, true) => 'float',
            $scalar === 'Boolean' => 'bool',
            \in_array($scalar, self::DATE_SCALARS, true) => '\DateTimeImmutable',
            default => 'string',
        };
    }

    /**
     * The scalars a property of a PHP type can be hydrated from, or null when the PHP type is not checked.
     *
     * @param string $phpType a builtin type or a short class name (`DateTimeImmutable`)
     *
     * @return string[]|null
     */
    public static function acceptedBy(string $phpType): ?array
    {
        return match ($phpType) {
            'int' => [...self::INT_SCALARS, 'ID'],
            'float' => self::FLOAT_SCALARS,
            'bool' => ['Boolean'],
            'string' => [...self::STRING_SCALARS, ...self::DATE_SCALARS],
            'DateTimeImmutable' => [...self::DATE_SCALARS, 'Time', 'String'],
            default => null,
        };
    }
}
