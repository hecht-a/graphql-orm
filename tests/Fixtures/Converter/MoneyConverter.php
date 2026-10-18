<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Converter;

use GraphqlOrm\Attribute\AsTypeConverter;
use GraphqlOrm\Converter\TypeConverterInterface;

/**
 * Money as an amount of cents, an Int of the API: the converter of every property typed Money.
 */
#[AsTypeConverter(Money::class)]
final class MoneyConverter implements TypeConverterInterface
{
    public function toPhp(mixed $value): Money
    {
        return new Money(is_numeric($value) ? (int) $value : 0);
    }

    public function toGraphql(mixed $value): int
    {
        \assert($value instanceof Money);

        return $value->cents;
    }
}
