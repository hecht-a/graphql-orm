<?php

declare(strict_types=1);

namespace GraphqlOrm\Execution;

use GraphqlOrm\Controller\EntityAutocompleteController;
use GraphqlOrm\Query\GraphqlQuery;
use GraphqlOrm\Repository\GraphqlEntityRepository;

/**
 * Where a query comes from, read from the call stack for the profiler: the method of the bundle the application
 * called, the line of the application calling it, and whether the serializer is on the way.
 *
 * @internal
 */
final class QueryOrigin
{
    /** Frames read to find the origin of a query: deep enough for a custom repository method calling the bundle. */
    private const ORIGIN_STACK_DEPTH = 40;

    private const LAZY_ORIGIN = 'lazy';

    /** The search of an autocomplete field, rather than the __invoke() of its controller. */
    private const AUTOCOMPLETE_ORIGIN = 'autocomplete';

    /** Frames read to find the serializer behind a lazy load: a normalizer reading a property is deep in the stack. */
    private const SERIALIZER_STACK_DEPTH = 80;

    private const SERIALIZER_NAMESPACE = 'Symfony\\Component\\Serializer\\';

    /**
     * The method of the bundle the application called to send the query, the outermost one in the call stack (e.g.
     * findBy, getResult, or a method of a custom repository), and the class of the entities it reads.
     *
     * @param bool $loadingLazily whether the query loads a relation on first access
     *
     * @return array{string|null, class-string|null}
     */
    public static function of(bool $loadingLazily): array
    {
        $origin = null;
        $entityClass = null;

        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, self::ORIGIN_STACK_DEPTH) as $frame) {
            $object = $frame['object'] ?? null;
            $class = $frame['class'] ?? null;

            $entityClass ??= match (true) {
                $object instanceof GraphqlEntityRepository => $object->getClassName(),
                $object instanceof GraphqlQuery => $object->getEntityClass(),
                default => null,
            };

            if ($class === null) {
                // A function, such as array_map(), between two methods of the bundle.
                continue;
            }

            if (!$object instanceof GraphqlEntityRepository && !self::isBundleClass($class)) {
                break;
            }

            $origin = $object instanceof EntityAutocompleteController ? self::AUTOCOMPLETE_ORIGIN : $frame['function'];
        }

        return [$loadingLazily ? self::LAZY_ORIGIN : $origin, $entityClass];
    }

    /**
     * Whether the serializer is on the way to the query, e.g. the ObjectNormalizer reading a relation not loaded yet.
     */
    public static function whileSerializing(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::SERIALIZER_STACK_DEPTH) as $frame) {
            if (str_starts_with($frame['class'] ?? '', self::SERIALIZER_NAMESPACE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The file and line of the application calling the bundle, e.g. src/Controller/TaskController.php:51.
     */
    public static function callSite(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::ORIGIN_STACK_DEPTH) as $frame) {
            $file = $frame['file'] ?? null;

            if (\is_string($file) && !str_starts_with($file, self::bundleDirectory()) && !str_contains($file, \DIRECTORY_SEPARATOR . 'vendor' . \DIRECTORY_SEPARATOR)) {
                return $file . (isset($frame['line']) ? ':' . $frame['line'] : '');
            }
        }

        return null;
    }

    /**
     * Whether the class is declared by the bundle, rather than by the application or another package.
     */
    private static function isBundleClass(string $class): bool
    {
        if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
            return false;
        }

        $file = (new \ReflectionClass($class))->getFileName();

        return \is_string($file) && str_starts_with($file, self::bundleDirectory());
    }

    private static function bundleDirectory(): string
    {
        return \dirname(__DIR__) . \DIRECTORY_SEPARATOR;
    }
}
