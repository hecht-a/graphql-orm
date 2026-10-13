<?php

declare(strict_types=1);

namespace GraphqlOrm\Hydrator;

use GraphqlOrm\Attribute\AfterHydrate;
use GraphqlOrm\Attribute\BeforeHydrate;
use GraphqlOrm\Exception\CastException;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Execution\GraphqlExecutionContext;
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
     * Reflection is resolved once per class, not once per hydrated row.
     *
     * @var array<string, array<string, \ReflectionProperty>>
     */
    private array $properties = [];

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
        $context->trace->hydrationMaxDepth = max(
            $context->trace->hydrationMaxDepth,
            $depth
        );

        $idField = $this->findUniqueField($metadata)?->mappedFrom;
        $uniqueField = $idField !== null ? ($data[$idField] ?? null) : null;

        if (!\is_int($uniqueField) && !\is_string($uniqueField)) {
            $uniqueField = null;
        }

        $known = $uniqueField !== null ? ($context->identityMap[$metadata->class][$uniqueField] ?? null) : null;

        // An entity met again, e.g. first as a nested relation reduced to its identifier, gets the fields of this row.
        if ($known !== null) {
            $wasComplete = $this->areMappedFieldsInitialized($known, $metadata);
            $this->hydrateFields($known, $metadata, $data, $context, $depth);

            if (!$wasComplete) {
                $this->callAfterHydrateMethods($known, $metadata);
            }

            return $known;
        }

        ++$context->trace->hydratedEntities;

        $entity = new ($metadata->class)();

        if ($uniqueField !== null) {
            $context->identityMap[$metadata->class][$uniqueField] = $entity;
        }

        $this->callBeforeHydrateMethods($entity, $data);
        $this->hydrateFields($entity, $metadata, $data, $context, $depth);
        $this->callAfterHydrateMethods($entity, $metadata);

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
                        fn (mixed $row) => $this->hydrate(
                            $relationMetadata,
                            \is_array($row) ? $row : throw InvalidGraphqlResponseException::expectedArray($row),
                            $context,
                            $depth + 1
                        ),
                        $context->dialect->extractCollection($value)
                    );
                } elseif ($value) {
                    ++$context->trace->hydratedRelations;

                    $value = $this->hydrate(
                        $relationMetadata,
                        $value,
                        $context,
                        $depth + 1
                    );
                }
            }

            $property = $this->property($metadata->class, $field->property);

            $value = $this->normalizeValue(
                $value,
                $property
            );

            $property->setValue($entity, $value);
        }
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
