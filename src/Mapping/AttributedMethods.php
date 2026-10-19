<?php

declare(strict_types=1);

namespace GraphqlOrm\Mapping;

/**
 * The public methods of an entity marked with an attribute: its hydration hooks (#[AfterHydrate]...) and lifecycle
 * callbacks (#[PrePersist]...). Read once for each class.
 *
 * @internal
 */
final class AttributedMethods
{
    /** @var array<class-string, array<class-string, list<\ReflectionMethod>>> */
    private static array $methods = [];

    /**
     * @param class-string $class
     * @param class-string $attribute
     *
     * @return list<\ReflectionMethod>
     */
    public static function of(string $class, string $attribute): array
    {
        return self::$methods[$class][$attribute] ??= array_values(array_filter(
            (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $method): bool => $method->getAttributes($attribute) !== [],
        ));
    }
}
