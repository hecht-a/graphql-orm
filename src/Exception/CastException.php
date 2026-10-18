<?php

declare(strict_types=1);

namespace GraphqlOrm\Exception;

final class CastException extends \UnexpectedValueException implements GraphqlOrmException
{
    public static function cannotCast(
        mixed $value,
        string $type,
    ): self {
        return new self(\sprintf(
            'Cannot cast value "%s" (%s) to %s.',
            \is_scalar($value) ? (string) $value : get_debug_type($value),
            get_debug_type($value),
            $type
        ));
    }

    public static function invalidDateTime(mixed $value): self
    {
        return new self(\sprintf(
            'Invalid datetime value "%s".',
            \is_scalar($value) ? (string) $value : get_debug_type($value),
        ));
    }

    public static function nullForParameter(string $class, string $parameter, string $field): self
    {
        return new self(\sprintf('"%s::$%s" cannot be null: the response holds no value for "%s".', $class, $parameter, $field));
    }
}
