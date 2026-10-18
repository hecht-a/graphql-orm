<?php

declare(strict_types=1);

namespace GraphqlOrm\Schema;

use GraphqlOrm\Converter\TypeConverterRegistry;
use GraphqlOrm\Exception\GraphqlOrmException;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;

/**
 * @internal
 */
final readonly class SchemaValidator
{
    public function __construct(
        private GraphqlEntityMetadataFactory $metadataFactory,
        private TypeConverterRegistry $typeConverters = new TypeConverterRegistry(),
    ) {
    }

    /**
     * @param class-string[]                                                                                                                  $entityClasses
     * @param array<string, array{kind: string, fields: array<string, array{kind: string, name: string|null, nullable?: bool, list?: bool}>}> $schemaTypes
     *
     * @return string[]
     */
    public function validate(array $entityClasses, array $schemaTypes, string $queryType = GraphqlSchema::DEFAULT_QUERY_TYPE): array
    {
        $violations = [];

        foreach ($entityClasses as $class) {
            try {
                $metadata = $this->metadataFactory->getMetadata($class);
            } catch (\Throwable $e) {
                $violations[] = \sprintf('Could not load metadata for "%s": %s', $class, $e->getMessage());
                continue;
            }

            $violations = [
                ...$violations,
                ...$this->validateEntity($metadata, $schemaTypes, $queryType),
            ];
        }

        return $violations;
    }

    /**
     * @param array<string, array{kind: string, fields: array<string, array{kind: string, name: string|null, nullable?: bool, list?: bool}>}> $schemaTypes
     *
     * @return string[]
     */
    private function validateEntity(GraphqlEntityMetadata $metadata, array $schemaTypes, string $queryType): array
    {
        $violations = [];
        $schema = new GraphqlSchema($schemaTypes, $queryType);
        $typeName = $schema->entityTypeOfRoot($metadata->name);

        if ($typeName === null) {
            $violations[] = \sprintf(
                '[%s] GraphQL type matching root "%s" not found in schema.',
                $metadata->class,
                $metadata->name,
            );

            return $violations;
        }

        $schemaFields = $schemaTypes[$typeName]['fields'];

        foreach ($metadata->fields as $field) {
            if ($field->ignoreValidation) {
                continue;
            }

            if ($field->embedded !== null && $field->embedded->prefix !== null) {
                $violations = [...$violations, ...$this->validateEmbedded($metadata->class, $field, $schemaFields, $typeName)];

                continue;
            }

            if (!isset($schemaFields[$field->mappedFrom])) {
                $suggestion = $this->suggestField($field->mappedFrom, array_keys($schemaFields));
                $didYouMean = $suggestion !== null ? \sprintf(' Did you mean "%s"?', $suggestion) : '';

                $violations[] = \sprintf(
                    '[%s] Field "%s" (mapped from "%s") does not exist on GraphQL type "%s".%s',
                    $metadata->class,
                    $field->property,
                    $field->mappedFrom,
                    $typeName,
                    $didYouMean,
                );

                continue;
            }

            $schemaField = $schemaFields[$field->mappedFrom];

            if ($field->embedded !== null) {
                $objectType = $schemaField['name'] ?? '';
                $violations = [...$violations, ...$this->validateEmbedded($metadata->class, $field, $schemaTypes[$objectType]['fields'] ?? [], $objectType)];

                continue;
            }

            $violations = [...$violations, ...$this->validateNullability($metadata->class, $field, $schemaField)];

            if ($field->relation !== null) {
                $violations = [...$violations, ...$this->validateRelation($metadata->class, $field, $schemaField, $schema)];

                continue;
            }

            if ($schemaField['kind'] !== 'SCALAR' && $schemaField['kind'] !== 'ENUM') {
                continue;
            }

            $converted = $this->validateConverter($metadata->class, $field, $schemaField['name']);

            if ($converted !== null) {
                $violations = [...$violations, ...$converted];

                continue;
            }

            $violations = [
                ...$violations,
                ...$this->validateScalar($metadata->class, $field->property, $field->mappedFrom, $schemaField['name']),
            ];
        }

        return $violations;
    }

    /**
     * The fields of a value object: those of the entity for prefixed ones, of its GraphQL object for the others.
     *
     * @param class-string                                                                        $entityClass
     * @param array<string, array{kind: string, name: string|null, nullable?: bool, list?: bool}> $schemaFields the fields holding them
     *
     * @return string[]
     */
    private function validateEmbedded(string $entityClass, GraphqlFieldMetadata $field, array $schemaFields, string $typeName): array
    {
        $embedded = $field->embedded;
        \assert($embedded !== null);
        $violations = [];

        foreach ($embedded->fields as $embeddedField) {
            $name = $embedded->graphqlName($embeddedField);
            $schemaField = $schemaFields[$name] ?? null;

            if ($schemaField === null) {
                $violations[] = \sprintf('[%s] Field "%s.%s" (mapped from "%s") does not exist on GraphQL type "%s".', $entityClass, $field->property, $embeddedField->property, $name, $typeName);

                continue;
            }

            if ($schemaField['kind'] !== 'SCALAR' && $schemaField['kind'] !== 'ENUM') {
                continue;
            }

            $violations = [
                ...$violations,
                ...($this->validateConverter($entityClass, $embeddedField, $schemaField['name'])
                    ?? $this->validateScalar($entityClass, $embeddedField->property, $name, $schemaField['name'], $embedded->class)),
            ];
        }

        return $violations;
    }

    /**
     * The type converter of a field, which must exist for a value object, and convert the scalar of the schema when it
     * tells its scalars; null for a field without value object, checked as a scalar.
     *
     * @param class-string $entityClass
     *
     * @return string[]|null
     */
    private function validateConverter(string $entityClass, GraphqlFieldMetadata $field, ?string $graphqlScalar): ?array
    {
        try {
            $converter = $this->typeConverters->converterFor($field->converter, $field->valueClass);
        } catch (InvalidArgumentException $exception) {
            return [\sprintf('[%s] Field "%s": %s', $entityClass, $field->mappedFrom, $exception->getMessage())];
        }

        if ($converter === null) {
            $valueClass = $field->valueClass;

            if ($valueClass === null || is_a($valueClass, \DateTimeInterface::class, true) || is_a($valueClass, \UnitEnum::class, true)) {
                return null;
            }

            return [\sprintf(
                '[%s] Field "%s" has PHP type "%s", which no type converter converts: register one with #[AsTypeConverter(%s::class)], or name it with #[Field(converter: ...)].',
                $entityClass,
                $field->mappedFrom,
                $valueClass,
                substr((string) strrchr('\\' . $valueClass, '\\'), 1),
            )];
        }

        $scalars = $this->typeConverters->scalarsOf($converter);

        if ($graphqlScalar === null || $scalars === [] || \in_array($graphqlScalar, $scalars, true)) {
            return [];
        }

        return [\sprintf(
            '[%s] Field "%s" is converted by %s from %s, but GraphQL scalar is "%s".',
            $entityClass,
            $field->mappedFrom,
            $converter::class,
            implode(', ', $scalars),
            $graphqlScalar,
        )];
    }

    /**
     * A field the schema declares nullable, hydrated into a property that does not accept null, fails with a TypeError
     * as soon as the API returns null. Nothing is checked when the schema does not tell the nullability.
     *
     * @param class-string                                                         $entityClass
     * @param array{kind: string, name: string|null, nullable?: bool, list?: bool} $schemaField
     *
     * @return string[]
     */
    private function validateNullability(string $entityClass, GraphqlFieldMetadata $field, array $schemaField): array
    {
        if (($schemaField['nullable'] ?? false) !== true || $field->isCollection) {
            return [];
        }

        $type = (new \ReflectionProperty($entityClass, $field->property))->getType();

        if (!$type instanceof \ReflectionNamedType || $type->allowsNull()) {
            return [];
        }

        $typeName = $type->isBuiltin() ? $type->getName() : substr((string) strrchr('\\' . $type->getName(), '\\'), 1);

        return [\sprintf(
            '[%s] Field "%s" is nullable in the schema but property "%s" does not accept null: declare it as "?%s".',
            $entityClass,
            $field->mappedFrom,
            $field->property,
            $typeName,
        )];
    }

    /**
     * A relation must return an object of the type of its target entity, as a list (or connection) for a collection and
     * a single object otherwise.
     *
     * @param class-string                                                         $entityClass
     * @param array{kind: string, name: string|null, nullable?: bool, list?: bool} $schemaField
     *
     * @return string[]
     */
    private function validateRelation(string $entityClass, GraphqlFieldMetadata $field, array $schemaField, GraphqlSchema $schema): array
    {
        if ($schemaField['kind'] !== 'OBJECT' || $schemaField['name'] === null) {
            return [\sprintf('[%s] Field "%s" is a relation to %s but is a %s in the schema.', $entityClass, $field->mappedFrom, $field->relation, $schemaField['kind'])];
        }

        $itemType = $schema->connectionItemType($schemaField['name']);
        $returnsList = ($schemaField['list'] ?? false) || $itemType !== null;

        if ($field->isCollection && !$returnsList) {
            return [\sprintf('[%s] Field "%s" is a collection but returns a single object in the schema: map it on a single relation.', $entityClass, $field->mappedFrom)];
        }

        if (!$field->isCollection && $returnsList) {
            return [\sprintf('[%s] Field "%s" is a single relation but returns a list in the schema: map it on a collection (array property).', $entityClass, $field->mappedFrom)];
        }

        $expectedType = $this->entityType((string) $field->relation, $schema);
        $actualType = $itemType ?? $schemaField['name'];

        if ($expectedType !== null && $expectedType !== $actualType) {
            return [\sprintf(
                '[%s] Field "%s" is a relation to %s (GraphQL type "%s") but returns the GraphQL type "%s".',
                $entityClass,
                $field->mappedFrom,
                $field->relation,
                $expectedType,
                $actualType,
            )];
        }

        return [];
    }

    /**
     * The GraphQL type of an entity, found from its root query field; null when it cannot be told.
     */
    private function entityType(string $entityClass, GraphqlSchema $schema): ?string
    {
        try {
            /** @var class-string $entityClass */
            return $schema->entityTypeOfRoot($this->metadataFactory->getMetadata($entityClass)->name);
        } catch (GraphqlOrmException) {
            return null;
        }
    }

    /**
     * @param class-string      $entityClass
     * @param class-string|null $declaringClass the class declaring the property, an embedded value object, if not the entity
     *
     * @return string[]
     */
    private function validateScalar(string $entityClass, string $property, string $mappedFrom, ?string $graphqlScalar, ?string $declaringClass = null): array
    {
        if ($graphqlScalar === null) {
            return [];
        }

        try {
            // The property of an embedded value object is declared by its class.
            $reflection = new \ReflectionProperty($declaringClass ?? $entityClass, $property);
        } catch (\ReflectionException) {
            return [];
        }

        $type = $reflection->getType();

        if (!$type instanceof \ReflectionNamedType) {
            return [];
        }

        /** @var class-string $typeName */
        $typeName = $type->getName();
        $phpType = $type->isBuiltin()
            ? $typeName
            : (new \ReflectionClass($typeName))->getShortName();

        $accepted = ScalarTypes::acceptedBy($phpType);

        if ($accepted === null) {
            return [];
        }

        if (!\in_array($graphqlScalar, $accepted, true)) {
            return [\sprintf(
                '[%s] Field "%s" has PHP type "%s" but GraphQL scalar is "%s" (expected one of: %s).',
                $entityClass,
                $mappedFrom,
                $phpType,
                $graphqlScalar,
                implode(', ', $accepted),
            )];
        }

        return [];
    }

    /**
     * @param string[] $candidates
     */
    private function suggestField(string $fieldName, array $candidates): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            $distance = levenshtein($fieldName, $candidate);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        if ($bestDistance > 3 || $best === null) {
            return null;
        }

        return $best;
    }
}
