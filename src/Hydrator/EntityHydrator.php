<?php

declare(strict_types=1);

namespace GraphqlOrm\Hydrator;

use GraphqlOrm\Attribute\AfterHydrate;
use GraphqlOrm\Attribute\BeforeHydrate;
use GraphqlOrm\Exception\CastException;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;

/**
 * @template T of object
 */
readonly class EntityHydrator
{
    /**
     * @param GraphqlEntityMetadataFactory<T> $metadataFactory
     */
    public function __construct(
        private GraphqlEntityMetadataFactory $metadataFactory,
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

        ++$context->trace->hydratedEntities;

        $idField = $this->findUniqueField($metadata->fields)?->mappedFrom;
        $uniqueField = $data[$idField] ?? null;

        if ($uniqueField !== null) {
            if (isset($context->identityMap[$metadata->class][$uniqueField])) {
                return $context->identityMap[$metadata->class][$uniqueField];
            }
        }

        /** @var T $entity */
        $entity = new ($metadata->class)();

        if ($uniqueField !== null) {
            $context->identityMap[$metadata->class][$uniqueField] = $entity;
        }

        $this->callBeforeHydrateMethods($entity, $data);

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
                        fn ($row) => $this->hydrate(
                            $relationMetadata,
                            $row,
                            $context,
                            $depth + 1
                        ),
                        $value
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

            $property = new \ReflectionProperty(
                $metadata->class,
                $field->property
            );

            if (!$property->isPublic()) {
                $property->setAccessible(true);
            }

            $value = $this->normalizeValue(
                $value,
                $property
            );

            $property->setValue($entity, $value);
        }

        $this->callAfterHydrateMethods($entity, $metadata);

        return $entity;
    }

    /**
     * @param array<string|int, mixed> $data
     */
    private function callBeforeHydrateMethods(object $entity, array $data): void
    {
        $reflection = new \ReflectionClass($entity);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(BeforeHydrate::class) === []) {
                continue;
            }

            $method->invoke($entity, $data);
        }
    }

    /**
     * @param T $entity
     */
    private function callAfterHydrateMethods(object $entity, GraphqlEntityMetadata $metadata): void
    {
        $reflection = new \ReflectionClass($entity);

        if (!$this->areMappedFieldsInitialized($reflection, $entity, $metadata)) {
            return;
        }

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(AfterHydrate::class) === []) {
                continue;
            }

            $method->invoke($entity);
        }
    }

    /**
     * @param \ReflectionClass<T> $reflection
     */
    private function areMappedFieldsInitialized(\ReflectionClass $reflection, object $entity, GraphqlEntityMetadata $metadata): bool
    {
        foreach ($metadata->fields as $field) {
            try {
                $property = $reflection->getProperty($field->property);
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

    /**
     * @param GraphqlFieldMetadata[] $fields
     */
    private function findUniqueField(array $fields): ?GraphqlFieldMetadata
    {
        return array_filter($fields, fn ($field) => $field->isIdentifier)[0] ?? null;
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
                        : CastException::cannotCast($value, 'float')
                    ),
                'bool' => match (true) {
                    \is_bool($value) => $value,
                    $value === 1,
                    $value === '1',
                    $value === 'true' => true,
                    $value === 0,
                    $value === '0',
                    $value === 'false' => false,
                    default => CastException::cannotCast($value, 'bool'),
                },
                'string' => \is_scalar($value)
                    ? (string) $value
                    : CastException::cannotCast($value, 'string'),
                'array' => (array) $value,
                default => $value,
            };
        }

        if ($typeName === \DateTimeImmutable::class) {
            if (is_numeric($value)) {
                return new \DateTimeImmutable(
                    '@' . ((int) $value / 1000)
                );
            }

            if (!\is_string($value)) {
                throw CastException::invalidDateTime($value);
            }

            return new \DateTimeImmutable($value);
        }

        return $value;
    }
}
