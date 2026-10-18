<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Converter;

use GraphqlOrm\Converter\TypeConverterInterface;

/**
 * A converter needing a service: it cannot be created by the bundle when it is not registered.
 */
final class ConfiguredConverter implements TypeConverterInterface
{
    public function __construct(
        private readonly string $prefix,
    ) {
    }

    public function toPhp(mixed $value): string
    {
        return $this->prefix . (\is_scalar($value) ? (string) $value : '');
    }

    public function toGraphql(mixed $value): mixed
    {
        return $value;
    }
}
