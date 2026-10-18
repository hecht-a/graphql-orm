<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Entity;

use GraphqlOrm\Attribute\AsTypeConverter;
use GraphqlOrm\Converter\TypeConverterInterface;

#[AsTypeConverter(Title::class)]
final class TitleConverter implements TypeConverterInterface
{
    public function toPhp(mixed $value): Title
    {
        return new Title(\is_string($value) ? $value : '');
    }

    public function toGraphql(mixed $value): string
    {
        \assert($value instanceof Title);

        return $value->value;
    }
}
