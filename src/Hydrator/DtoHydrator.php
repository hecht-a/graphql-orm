<?php

declare(strict_types=1);

namespace GraphqlOrm\Hydrator;

use GraphqlOrm\Converter\TypeConverterRegistry;
use GraphqlOrm\Exception\CastException;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Metadata\DtoMetadata;
use GraphqlOrm\Metadata\DtoParameterMetadata;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;

/**
 * Creates DTOs through their constructor from the rows of a response, their collection envelopes removed: no identity
 * map, no second-level cache, no lazy loading.
 *
 * @internal
 */
final class DtoHydrator
{
    /**
     * @param array<mixed> $row keyed by GraphQL names
     *
     * @throws CastException            when a value cannot be given to its parameter
     * @throws InvalidArgumentException when the type converter of a parameter is not registered
     */
    public static function hydrate(DtoMetadata $metadata, array $row, ?TypeConverterRegistry $converters = null): object
    {
        $arguments = [];

        foreach ($metadata->parameters as $parameter) {
            $value = self::value($parameter, $row, $converters);

            if ($value === null && !$parameter->allowsNull) {
                if ($parameter->hasDefault) {
                    continue;
                }

                throw CastException::nullForParameter($metadata->class, $parameter->name, implode('.', $parameter->path));
            }

            $arguments[$parameter->name] = $value;
        }

        return new ($metadata->class)(...$arguments);
    }

    /**
     * @param array<mixed> $row
     */
    private static function value(DtoParameterMetadata $parameter, array $row, ?TypeConverterRegistry $converters): mixed
    {
        $value = $row;

        foreach ($parameter->path as $name) {
            if (!\is_array($value) || !\array_key_exists($name, $value)) {
                return null;
            }

            $value = $value[$name];
        }

        $nested = $parameter->nested;

        if ($parameter->embedded !== null) {
            return \is_array($value)
                ? EmbeddedRows::create($parameter->embedded, $value, static function (mixed $value, \ReflectionProperty $property, GraphqlFieldMetadata $field) use ($converters): mixed {
                    $converter = $value !== null ? $converters?->converterFor($field->converter, $field->valueClass) : null;

                    return $converter !== null ? $converter->toPhp($value) : ValueCaster::cast($value, $property->getType());
                })
                : null;
        }

        if ($nested === null) {
            $converter = $value !== null ? $converters?->converterFor($parameter->converter, $parameter->valueClass) : null;

            return $converter !== null ? $converter->toPhp($value) : ValueCaster::cast($value, $parameter->type);
        }

        if (!\is_array($value)) {
            return null;
        }

        if (!$parameter->isCollection) {
            return self::hydrate($nested, $value, $converters);
        }

        return array_values(array_map(
            static fn (mixed $item): object => self::hydrate($nested, \is_array($item) ? $item : [], $converters),
            $value,
        ));
    }
}
