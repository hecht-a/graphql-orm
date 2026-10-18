<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Converter;

use GraphqlOrm\Attribute\AsTypeConverter;
use GraphqlOrm\Converter\TypeConverterInterface;

/**
 * A custom scalar of the API, Siret: make:entity --from-schema types its fields with the value object.
 */
#[AsTypeConverter(Siret::class, scalars: ['Siret'])]
final class SiretConverter implements TypeConverterInterface
{
    public function toPhp(mixed $value): Siret
    {
        return new Siret(\is_string($value) ? $value : '');
    }

    public function toGraphql(mixed $value): string
    {
        \assert($value instanceof Siret);

        return $value->value;
    }
}
