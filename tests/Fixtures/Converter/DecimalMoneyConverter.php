<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Converter;

use GraphqlOrm\Converter\TypeConverterInterface;

/**
 * Money as a decimal amount, a Float of the API: named by #[Field(converter: ...)], not registered.
 */
final class DecimalMoneyConverter implements TypeConverterInterface
{
    public function toPhp(mixed $value): Money
    {
        return new Money((int) round((is_numeric($value) ? (float) $value : 0.0) * 100));
    }

    public function toGraphql(mixed $value): float
    {
        \assert($value instanceof Money);

        return $value->cents / 100;
    }
}
