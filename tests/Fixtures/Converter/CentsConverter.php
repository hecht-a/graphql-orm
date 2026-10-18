<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Converter;

use GraphqlOrm\Attribute\AsTypeConverter;
use GraphqlOrm\Converter\TypeConverterInterface;

/**
 * Money from cents, which tells the scalars it converts: the schema validation checks them.
 */
#[AsTypeConverter(Money::class, scalars: ['Int'])]
final class CentsConverter implements TypeConverterInterface
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
