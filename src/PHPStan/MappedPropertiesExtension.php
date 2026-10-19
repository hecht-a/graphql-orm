<?php

declare(strict_types=1);

namespace GraphqlOrm\PHPStan;

use GraphqlOrm\Mapping\Embedded;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Relation;
use PHPStan\Reflection\ExtendedPropertyReflection;
use PHPStan\Rules\Properties\ReadWritePropertiesExtension;

/**
 * The properties mapped to the API are written by the hydration and read by flush(), as phpstan-doctrine tells of the
 * columns of an entity: a readonly identifier needs no constructor.
 *
 * @internal
 */
final class MappedPropertiesExtension implements ReadWritePropertiesExtension
{
    private const MAPPING_ATTRIBUTES = [Field::class, Relation::class, Embedded::class];

    public function isAlwaysRead(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return $this->isMapped($property, $propertyName);
    }

    public function isAlwaysWritten(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return $this->isMapped($property, $propertyName);
    }

    public function isInitialized(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return $this->isMapped($property, $propertyName);
    }

    private function isMapped(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        $class = $property->getDeclaringClass()->getNativeReflection();

        if (!$class->hasProperty($propertyName)) {
            return false;
        }

        foreach (self::MAPPING_ATTRIBUTES as $attribute) {
            if ($class->getProperty($propertyName)->getAttributes($attribute) !== []) {
                return true;
            }
        }

        return false;
    }
}
