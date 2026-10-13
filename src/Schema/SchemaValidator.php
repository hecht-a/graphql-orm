<?php

declare(strict_types=1);

namespace GraphqlOrm\Schema;

use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;

/**
 * @internal
 */
final readonly class SchemaValidator
{
    public function __construct(
        private GraphqlEntityMetadataFactory $metadataFactory,
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
        $typeName = (new GraphqlSchema($schemaTypes, $queryType))->entityTypeOfRoot($metadata->name);

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
     * @param class-string $entityClass
     *
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
