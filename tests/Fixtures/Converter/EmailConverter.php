<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Converter;

use GraphqlOrm\Attribute\AsTypeConverter;
use GraphqlOrm\Converter\TypeConverterInterface;

#[AsTypeConverter(Email::class)]
final class EmailConverter implements TypeConverterInterface
{
    public function toPhp(mixed $value): Email
    {
        return new Email(\is_string($value) ? $value : '');
    }

    public function toGraphql(mixed $value): string
    {
        \assert($value instanceof Email);

        return $value->value;
    }
}
