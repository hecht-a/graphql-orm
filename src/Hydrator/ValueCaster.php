<?php

declare(strict_types=1);

namespace GraphqlOrm\Hydrator;

use GraphqlOrm\Exception\CastException;

/**
 * Converts the values of a response to the types of the properties and parameters they are given to.
 *
 * @internal
 */
final class ValueCaster
{
    private const DATE_CLASSES = [\DateTimeImmutable::class, \DateTimeInterface::class, \DateTime::class];
    private const MILLISECONDS_PER_SECOND = 1000;

    /**
     * The value of a response converted to a type declared in PHP: the builtin types, dates and backed enums; the
     * others are returned as is.
     *
     * @throws CastException when the value cannot be converted
     */
    public static function cast(mixed $value, ?\ReflectionType $type): mixed
    {
        if ($value === null) {
            return null;
        }

        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        if ($type->isBuiltin()) {
            return self::castBuiltin($value, $typeName);
        }

        if (\in_array($typeName, self::DATE_CLASSES, true)) {
            return self::castDate($value, $typeName);
        }

        if (is_subclass_of($typeName, \BackedEnum::class)) {
            return self::castEnum($value, $typeName);
        }

        return $value;
    }

    private static function castBuiltin(mixed $value, string $typeName): mixed
    {
        return match ($typeName) {
            'int' => \is_float($value)
                ? $value
                : (is_numeric($value)
                    ? (int) $value
                    : throw CastException::cannotCast($value, 'int')
                ),
            'float' => \is_float($value)
                ? $value
                : (is_numeric($value)
                    ? (float) $value
                    : throw CastException::cannotCast($value, 'float')
                ),
            'bool' => match (true) {
                \is_bool($value) => $value,
                $value === 1,
                $value === '1',
                $value === 'true' => true,
                $value === 0,
                $value === '0',
                $value === 'false' => false,
                default => throw CastException::cannotCast($value, 'bool'),
            },
            'string' => \is_scalar($value)
                ? (string) $value
                : throw CastException::cannotCast($value, 'string'),
            'array' => (array) $value,
            default => $value,
        };
    }

    /**
     * Numeric values are Unix timestamps in milliseconds.
     */
    private static function castDate(mixed $value, string $typeName): \DateTimeInterface
    {
        try {
            $date = match (true) {
                is_numeric($value) => new \DateTimeImmutable('@' . ((int) $value / self::MILLISECONDS_PER_SECOND)),
                \is_string($value) => new \DateTimeImmutable($value),
                default => throw CastException::invalidDateTime($value),
            };
        } catch (\Exception $e) {
            throw $e instanceof CastException ? $e : CastException::invalidDateTime($value);
        }

        return $typeName === \DateTime::class ? \DateTime::createFromImmutable($date) : $date;
    }

    /**
     * @param class-string<\BackedEnum> $enumClass
     */
    private static function castEnum(mixed $value, string $enumClass): \BackedEnum
    {
        if (!\is_int($value) && !\is_string($value)) {
            throw CastException::cannotCast($value, $enumClass);
        }

        try {
            return $enumClass::from($value);
        } catch (\ValueError|\TypeError) {
            throw CastException::cannotCast($value, $enumClass);
        }
    }
}
