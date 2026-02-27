<?php

declare(strict_types=1);

namespace GraphqlOrm\Schema;

use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;

/**
 * @template T of object
 */
final readonly class SchemaValidator
{
    /**
     * @var array<string, string[]>
     */
    private const array SCALAR_MAP = [
        'int' => ['Int', 'ID'],
        'float' => ['Float'],
        'string' => ['String', 'ID', 'Date', 'DateTime', 'Time', 'JSON', 'UUID'],
        'bool' => ['Boolean'],
        'DateTimeImmutable' => ['DateTime', 'Date', 'Time', 'String'],
    ];

    /**
     * @param GraphqlEntityMetadataFactory<T> $metadataFactory
     */
    public function __construct(
        private GraphqlEntityMetadataFactory $metadataFactory,
    ) {
    }

    /**
     * @param class-string[]                                                                                    $entityClasses
     * @param array<string, array{kind: string, fields: array<string, array{kind: string, name: string|null}>}> $schemaTypes
     *
     * @return string[]
     */
    public function validate(array $entityClasses, array $schemaTypes): array
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
                ...$this->validateEntity($metadata, $schemaTypes),
            ];
        }

        return $violations;
    }

    /**
     * @param array<string, array{kind: string, fields: array<string, array{kind: string, name: string|null}>}> $schemaTypes
     *
     * @return string[]
     */
    private function validateEntity(GraphqlEntityMetadata $metadata, array $schemaTypes): array
    {
        $violations = [];
        $typeName = $this->resolveGraphqlTypeName($metadata->name, $schemaTypes);

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

            if ($field->relation !== null) {
                continue;
            }

            $schemaField = $schemaFields[$field->mappedFrom];

            if ($schemaField['kind'] !== 'SCALAR' && $schemaField['kind'] !== 'ENUM') {
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
     * @param array<string, array{kind: string, fields: array<string, array{kind: string, name: string|null}>}> $schemaTypes
     */
    private function resolveGraphqlTypeName(string $rootName, array $schemaTypes): ?string
    {
        $candidates = [
            ucfirst($rootName),
            ucfirst(rtrim($rootName, 's')),
            $rootName,
        ];

        foreach ($candidates as $candidate) {
            if (isset($schemaTypes[$candidate]) && $schemaTypes[$candidate]['kind'] === 'OBJECT') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function validateScalar(string $entityClass, string $property, string $mappedFrom, ?string $graphqlScalar, ): array
    {
        if ($graphqlScalar === null) {
            return [];
        }

        try {
            $reflection = new \ReflectionProperty($entityClass, $property);
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

        $accepted = self::SCALAR_MAP[$phpType] ?? null;

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
