<?php

declare(strict_types=1);

namespace GraphqlOrm\Hydrator;

use GraphqlOrm\Metadata\GraphqlEmbeddedMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;

/**
 * The embedded value objects of a row: their prefixed fields nested under one key, as the GraphQL objects of the others,
 * so that both are read, kept in the second-level cache and hydrated the same way.
 *
 * @internal
 */
final class EmbeddedRows
{
    /**
     * The row, the prefixed fields of each embedded value object nested under its key: `total_amount` becomes
     * `total.amount`, and null when all of them are null. A row nested already is left as it is.
     *
     * @param array<string|int, mixed> $row
     *
     * @return array<string|int, mixed>
     */
    public static function nest(GraphqlEntityMetadata $metadata, array $row): array
    {
        foreach ($metadata->fields as $field) {
            $embedded = $field->embedded;

            if ($embedded === null || $embedded->prefix === null || \array_key_exists($field->mappedFrom, $row)) {
                continue;
            }

            $values = [];

            foreach ($embedded->fields as $embeddedField) {
                $key = $embedded->graphqlName($embeddedField);

                if (\array_key_exists($key, $row)) {
                    $values[$embeddedField->mappedFrom] = $row[$key];
                    unset($row[$key]);
                }
            }

            if ($values !== []) {
                $row[$field->mappedFrom] = array_filter($values, static fn (mixed $value): bool => $value !== null) === [] ? null : $values;
            }
        }

        return $row;
    }

    /**
     * Whether the values hold every field the value object needs: those its constructor or its properties leave without
     * a default value.
     *
     * @param array<mixed> $values keyed by the GraphQL names of the fields of the value object
     */
    public static function isComplete(GraphqlEmbeddedMetadata $embedded, array $values): bool
    {
        $defaults = self::defaults($embedded->class);

        foreach ($embedded->fields as $field) {
            if (!\array_key_exists($field->mappedFrom, $values) && !\in_array($field->property, $defaults, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Creates the value object through its constructor, the values given by the name of their property, or sets its
     * properties when it has no constructor.
     *
     * @param array<mixed>                                                      $values keyed by the GraphQL names of its fields
     * @param \Closure(mixed, \ReflectionProperty, GraphqlFieldMetadata): mixed $cast   converts a value to its property
     */
    public static function create(GraphqlEmbeddedMetadata $embedded, array $values, \Closure $cast): object
    {
        $class = new \ReflectionClass($embedded->class);
        $arguments = [];

        foreach ($embedded->fields as $field) {
            if (\array_key_exists($field->mappedFrom, $values)) {
                $arguments[$field->property] = $cast($values[$field->mappedFrom], $class->getProperty($field->property), $field);
            }
        }

        if ($class->getConstructor() !== null) {
            return $class->newInstanceArgs($arguments);
        }

        $object = $class->newInstanceWithoutConstructor();

        foreach ($arguments as $property => $value) {
            $class->getProperty($property)->setValue($object, $value);
        }

        return $object;
    }

    /**
     * The properties of a class that a missing value leaves to a default: a parameter of the constructor, or a property.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function defaults(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor !== null) {
            return array_values(array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                array_filter($constructor->getParameters(), static fn (\ReflectionParameter $parameter): bool => $parameter->isDefaultValueAvailable()),
            ));
        }

        return array_values(array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            array_filter($reflection->getProperties(), static fn (\ReflectionProperty $property): bool => $property->hasDefaultValue()),
        ));
    }
}
