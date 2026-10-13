<?php

declare(strict_types=1);

namespace GraphqlOrm\PHPStan;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;

/**
 * Reads, at analysis time, the entity class of a generic repository or query builder and the constant values of the
 * arguments of a call.
 *
 * @internal
 */
final class EntityClassResolver
{
    /**
     * @param class-string $genericClass a class declaring the `T` template, e.g. GraphqlEntityRepository
     *
     * @return list<string> the entity classes, empty when not known statically
     */
    public static function entityClasses(MethodCall $call, Scope $scope, string $genericClass): array
    {
        $callerType = $scope->getType($call->var);

        if (!(new \PHPStan\Type\ObjectType($genericClass))->isSuperTypeOf($callerType)->yes()) {
            return [];
        }

        return $callerType->getTemplateType($genericClass, 'T')->getObjectClassNames();
    }

    /**
     * The argument at a position or with a name, unless arguments are unpacked.
     */
    public static function argument(MethodCall $call, int $position, string $name): ?Arg
    {
        foreach ($call->getArgs() as $index => $arg) {
            if ($arg->unpack) {
                return null;
            }

            if ($arg->name !== null ? $arg->name->toString() === $name : $index === $position) {
                return $arg;
            }
        }

        return null;
    }

    /**
     * @return list<string> the constant string keys of an array argument, empty when not known statically
     */
    public static function constantKeys(?Arg $arg, Scope $scope): array
    {
        if ($arg === null) {
            return [];
        }

        $keys = [];

        foreach ($scope->getType($arg->value)->getConstantArrays() as $array) {
            foreach ($array->getKeyTypes() as $keyType) {
                foreach ($keyType->getConstantStrings() as $key) {
                    $keys[] = $key->getValue();
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<string> the constant string values of an argument, empty when not known statically
     */
    public static function constantStrings(Arg $arg, Scope $scope): array
    {
        return array_map(fn ($string) => $string->getValue(), $scope->getType($arg->value)->getConstantStrings());
    }
}
