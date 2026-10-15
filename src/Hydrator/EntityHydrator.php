<?php

declare(strict_types=1);

namespace GraphqlOrm\Hydrator;

use GraphqlOrm\Attribute\AfterHydrate;
use GraphqlOrm\Attribute\BeforeHydrate;
use GraphqlOrm\Exception\CastException;
use GraphqlOrm\Exception\EntityNotFoundException;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Mapping\Fetch;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;

/**
 * @internal
 */
class EntityHydrator
{
    private const DATE_CLASSES = [\DateTimeImmutable::class, \DateTimeInterface::class, \DateTime::class];
    private const MILLISECONDS_PER_SECOND = 1000;

    /**
     * Field GraphQL servers may add to any object, ignored when telling whether a row holds more than the identifier.
     */
    private const TYPENAME_FIELD = '__typename';

    /**
     * Reflection is resolved once per class, not once per hydrated row.
     *
     * @var array<string, array<string, \ReflectionProperty>>
     */
    private array $properties = [];

    /**
     * @var array<string, \ReflectionClass<object>>
     */
    private array $classes = [];

    /**
     * @var array<string, array<class-string, \ReflectionMethod[]>>
     */
    private array $hookMethods = [];

    public function __construct(
        private readonly GraphqlEntityMetadataFactory $metadataFactory,
    ) {
    }

    /**
     * @param array<string|int, mixed> $data
     */
    public function hydrate(
        GraphqlEntityMetadata $metadata,
        array $data,
        GraphqlExecutionContext $context,
        int $depth = 0,
    ): object {
        return $this->hydrateEntity($metadata, $data, $context, $depth, lazy: false);
    }

    /**
     * @param array<string|int, mixed> $data
     * @param bool                     $lazy whether the relation holding this row is fetched lazily
     */
    private function hydrateEntity(
        GraphqlEntityMetadata $metadata,
        array $data,
        GraphqlExecutionContext $context,
        int $depth,
        bool $lazy,
    ): object {
        $context->trace->hydrationMaxDepth = max(
            $context->trace->hydrationMaxDepth,
            $depth
        );

        $identifier = $this->findUniqueField($metadata);
        $idField = $identifier?->mappedFrom;
        $uniqueField = $idField !== null ? ($data[$idField] ?? null) : null;

        if (!\is_int($uniqueField) && !\is_string($uniqueField)) {
            $uniqueField = null;
        }

        $known = $uniqueField !== null ? ($context->identityMap[$metadata->class][$uniqueField] ?? null) : null;

        // An entity met again, e.g. first as a nested relation reduced to its identifier, gets the fields of this row.
        if ($known !== null && $this->isUninitializedGhost($known)) {
            return $this->markComplete($this->completeGhost($known, $metadata, $data, $context, $depth), $metadata, $data, $context);
        }

        if ($known !== null) {
            $wasComplete = $this->areMappedFieldsInitialized($known, $metadata);
            $this->hydrateFields($known, $metadata, $data, $context, $depth);

            if (!$wasComplete) {
                $this->callAfterHydrateMethods($known, $metadata);
            }

            return $this->markComplete($known, $metadata, $data, $context);
        }

        ++$context->trace->hydratedEntities;

        if ($identifier !== null && $uniqueField !== null && $context->lazyLoader !== null && ($lazy || $context->lazyLoading) && $this->isReducedToIdentifier($metadata, $data, $identifier->mappedFrom)) {
            $ghost = $this->lazyGhost($metadata, $identifier, $uniqueField, $context);
            $property = $this->property($metadata->class, $identifier->property);
            $property->setRawValueWithoutLazyInitialization($ghost, $this->normalizeValue($data[$identifier->mappedFrom], $property));

            return $context->identityMap[$metadata->class][$uniqueField] = $ghost;
        }

        $entity = new ($metadata->class)();

        if ($uniqueField !== null) {
            $context->identityMap[$metadata->class][$uniqueField] = $entity;
        }

        $this->callBeforeHydrateMethods($entity, $data);
        $this->hydrateFields($entity, $metadata, $data, $context, $depth);
        $this->callAfterHydrateMethods($entity, $metadata);

        return $this->markComplete($entity, $metadata, $data, $context);
    }

    /**
     * Records an entity hydrated from a row holding every mapped field: one known only in part (a selection of its
     * fields) is not, whatever the default values of its properties.
     *
     * @param array<string|int, mixed> $data
     */
    private function markComplete(object $entity, GraphqlEntityMetadata $metadata, array $data, GraphqlExecutionContext $context): object
    {
        foreach ($metadata->fields as $field) {
            if (!\array_key_exists($field->mappedFrom, $data)) {
                return $entity;
            }
        }

        $context->completeEntities[] = $entity;

        return $entity;
    }

    /**
     * @param array<string|int, mixed> $data
     */
    private function hydrateFields(object $entity, GraphqlEntityMetadata $metadata, array $data, GraphqlExecutionContext $context, int $depth): void
    {
        foreach ($metadata->fields as $field) {
            if (!\array_key_exists($field->mappedFrom, $data)) {
                continue;
            }

            $value = $data[$field->mappedFrom];

            if ($field->relation !== null && \is_array($value)) {
                $relationMetadata = $this
                    ->metadataFactory
                    ->getMetadata($field->relation);

                if ($field->isCollection) {
                    ++$context->trace->hydratedCollections;

                    $value = array_map(
                        fn (mixed $row) => $this->hydrateEntity(
                            $relationMetadata,
                            \is_array($row) ? $row : throw InvalidGraphqlResponseException::expectedArray($row),
                            $context,
                            $depth + 1,
                            $field->fetch === Fetch::Lazy,
                        ),
                        $context->dialect->extractCollection($value)
                    );
                } elseif ($value) {
                    ++$context->trace->hydratedRelations;

                    $value = $this->hydrateEntity(
                        $relationMetadata,
                        $value,
                        $context,
                        $depth + 1,
                        $field->fetch === Fetch::Lazy,
                    );
                }
            }

            $property = $this->property($metadata->class, $field->property);

            $value = $this->normalizeValue(
                $value,
                $property
            );

            $this->isUninitializedGhost($entity)
                ? $property->setRawValueWithoutLazyInitialization($entity, $value)
                : $property->setValue($entity, $value);
        }
    }

    /**
     * A relation beyond max_depth only gets its identifier selected: nothing else to hydrate from this row.
     *
     * @param array<string|int, mixed> $data
     */
    private function isReducedToIdentifier(GraphqlEntityMetadata $metadata, array $data, string $identifierField): bool
    {
        return \count($metadata->fields) > 1 && array_diff(array_keys($data), [$identifierField, self::TYPENAME_FIELD]) === [];
    }

    /**
     * An entity of which only the identifier is set: its other properties are loaded on first access. The other ghosts
     * of its class met in the same response are loaded by the same query when the dialect allows it.
     */
    private function lazyGhost(GraphqlEntityMetadata $metadata, GraphqlFieldMetadata $identifierField, int|string $identifier, GraphqlExecutionContext $context): object
    {
        $class = $metadata->class;

        return $this->classReflection($class)->newLazyGhost(function (object $ghost) use ($class, $identifierField, $identifier, $context): void {
            $pending = [$identifier => $ghost];

            foreach ($context->identityMap[$class] ?? [] as $key => $entity) {
                if ($entity !== $ghost && $this->isUninitializedGhost($entity)) {
                    $pending[$key] = $entity;
                }
            }

            $loader = $context->lazyLoader ?? throw EntityNotFoundException::forIdentifier($class, $identifier);
            $identifierProperty = $this->property($class, $identifierField->property);
            $loaded = [];

            foreach ($loader($class, array_keys($pending)) as $entity) {
                $key = $identifierProperty->getValue($entity);

                if (\is_int($key) || \is_string($key)) {
                    $loaded[(string) $key] = $entity;
                }
            }

            $this->copyLoaded($loaded[(string) $identifier] ?? throw EntityNotFoundException::forIdentifier($class, $identifier), $ghost, $identifierField, raw: false);

            // The other ghosts are completed without their initializer; one missing from the result stays a ghost.
            foreach ($pending as $key => $other) {
                if ($other !== $ghost && isset($loaded[(string) $key])) {
                    $this->copyLoaded($loaded[(string) $key], $other, $identifierField, raw: true);
                    $this->classReflection($class)->markLazyObjectAsInitialized($other);
                }
            }
        });
    }

    /**
     * PHP gives the properties of a ghost being initialized their default values: every property but the identifier,
     * already set, is copied from the loaded entity.
     */
    private function copyLoaded(object $loaded, object $ghost, GraphqlFieldMetadata $identifierField, bool $raw): void
    {
        foreach ($this->classReflection($ghost::class)->getProperties() as $property) {
            if ($property->isStatic() || $property->getName() === $identifierField->property || !$property->isInitialized($loaded)) {
                continue;
            }

            $raw
                ? $property->setRawValueWithoutLazyInitialization($ghost, $property->getValue($loaded))
                : $property->setValue($ghost, $property->getValue($loaded));
        }
    }

    private function isUninitializedGhost(object $entity): bool
    {
        return $this->classReflection($entity::class)->isUninitializedLazyObject($entity);
    }

    /**
     * A ghost met again: the fields of this row are set without loading it, and a row holding every mapped field makes
     * the query needless. PHP reports the properties of a ghost having a default value as initialized, so completeness
     * is read from the row, not from the object.
     *
     * @param array<string|int, mixed> $data
     */
    private function completeGhost(object $ghost, GraphqlEntityMetadata $metadata, array $data, GraphqlExecutionContext $context, int $depth): object
    {
        $this->hydrateFields($ghost, $metadata, $data, $context, $depth);

        foreach ($metadata->fields as $field) {
            if (!\array_key_exists($field->mappedFrom, $data)) {
                return $ghost;
            }
        }

        $this->classReflection($ghost::class)->markLazyObjectAsInitialized($ghost);
        $this->callAfterHydrateMethods($ghost, $metadata);

        return $ghost;
    }

    /**
     * @template C of object
     *
     * @param class-string<C> $class
     *
     * @return \ReflectionClass<C>
     */
    private function classReflection(string $class): \ReflectionClass
    {
        /** @var \ReflectionClass<C> $reflection */
        $reflection = $this->classes[$class] ??= new \ReflectionClass($class);

        return $reflection;
    }

    /**
     * @param array<string|int, mixed> $data
     */
    private function callBeforeHydrateMethods(object $entity, array $data): void
    {
        foreach ($this->hookMethods($entity::class, BeforeHydrate::class) as $method) {
            $method->invoke($entity, $data);
        }
    }

    private function callAfterHydrateMethods(object $entity, GraphqlEntityMetadata $metadata): void
    {
        $methods = $this->hookMethods($entity::class, AfterHydrate::class);

        if ($methods === [] || !$this->areMappedFieldsInitialized($entity, $metadata)) {
            return;
        }

        foreach ($methods as $method) {
            $method->invoke($entity);
        }
    }

    /**
     * @param class-string $class
     * @param class-string $attribute
     *
     * @return \ReflectionMethod[]
     */
    private function hookMethods(string $class, string $attribute): array
    {
        if (!isset($this->hookMethods[$class][$attribute])) {
            $this->hookMethods[$class][$attribute] = array_values(array_filter(
                (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC),
                fn (\ReflectionMethod $method) => $method->getAttributes($attribute) !== [],
            ));
        }

        return $this->hookMethods[$class][$attribute];
    }

    /**
     * @param class-string $class
     */
    private function property(string $class, string $name): \ReflectionProperty
    {
        return $this->properties[$class][$name] ??= new \ReflectionProperty($class, $name);
    }

    private function areMappedFieldsInitialized(object $entity, GraphqlEntityMetadata $metadata): bool
    {
        foreach ($metadata->fields as $field) {
            try {
                $property = $this->property($entity::class, $field->property);
            } catch (\ReflectionException) {
                continue;
            }

            if ($property->hasDefaultValue() || $property->getType() === null) {
                continue;
            }

            if (!$property->isInitialized($entity)) {
                return false;
            }
        }

        return true;
    }

    private function findUniqueField(GraphqlEntityMetadata $metadata): ?GraphqlFieldMetadata
    {
        if ($metadata->identifier !== null) {
            return $metadata->identifier;
        }

        foreach ($metadata->fields as $field) {
            if ($field->isIdentifier) {
                return $field;
            }
        }

        return null;
    }

    private function normalizeValue(
        mixed $value,
        \ReflectionProperty $property,
    ): mixed {
        if ($value === null) {
            return null;
        }

        $type = $property->getType();

        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        if ($type->isBuiltin()) {
            return $this->castBuiltin($value, $typeName);
        }

        if (\in_array($typeName, self::DATE_CLASSES, true)) {
            return $this->castDate($value, $typeName);
        }

        if (is_subclass_of($typeName, \BackedEnum::class)) {
            return $this->castEnum($value, $typeName);
        }

        return $value;
    }

    private function castBuiltin(mixed $value, string $typeName): mixed
    {
        return match ($typeName) {
            'int' => \is_float($value)
                ? $value
                : (is_numeric($value)
                    ? (int) $value
                    : throw CastException::cannotCast($value, 'int')
                ),
            'float' => \is_float($value)
                ? $value
                : (is_numeric($value)
                    ? (float) $value
                    : throw CastException::cannotCast($value, 'float')
                ),
            'bool' => match (true) {
                \is_bool($value) => $value,
                $value === 1,
                $value === '1',
                $value === 'true' => true,
                $value === 0,
                $value === '0',
                $value === 'false' => false,
                default => throw CastException::cannotCast($value, 'bool'),
            },
            'string' => \is_scalar($value)
                ? (string) $value
                : throw CastException::cannotCast($value, 'string'),
            'array' => (array) $value,
            default => $value,
        };
    }

    /**
     * Numeric values are Unix timestamps in milliseconds.
     */
    private function castDate(mixed $value, string $typeName): \DateTimeInterface
    {
        try {
            $date = match (true) {
                is_numeric($value) => new \DateTimeImmutable('@' . ((int) $value / self::MILLISECONDS_PER_SECOND)),
                \is_string($value) => new \DateTimeImmutable($value),
                default => throw CastException::invalidDateTime($value),
            };
        } catch (\Exception $e) {
            throw $e instanceof CastException ? $e : CastException::invalidDateTime($value);
        }

        return $typeName === \DateTime::class ? \DateTime::createFromImmutable($date) : $date;
    }

    /**
     * @param class-string<\BackedEnum> $enumClass
     */
    private function castEnum(mixed $value, string $enumClass): \BackedEnum
    {
        if (!\is_int($value) && !\is_string($value)) {
            throw CastException::cannotCast($value, $enumClass);
        }

        try {
            return $enumClass::from($value);
        } catch (\ValueError|\TypeError) {
            throw CastException::cannotCast($value, $enumClass);
        }
    }
}
