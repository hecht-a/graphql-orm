<?php

declare(strict_types=1);

namespace GraphqlOrm\Codegen;

use GraphqlOrm\Schema\GraphqlSchema;

/**
 * Turns the fields of a GraphQL object type into the properties of the entity to generate.
 *
 * @phpstan-import-type SchemaField from GraphqlSchema
 *
 * @internal
 */
final readonly class SchemaPropertyMapper
{
    private const IDENTIFIER_FIELD = 'id';

    private const IDENTIFIER_SCALAR = 'ID';

    /**
     * GraphQL scalar => PHP type; any other scalar or enum is generated as a string. Byte, Short, Long, Single and
     * Decimal are emitted by Data API Builder.
     */
    private const PHP_TYPES = [
        'Int' => 'int',
        'Byte' => 'int',
        'Short' => 'int',
        'Long' => 'int',
        'Float' => 'float',
        'Single' => 'float',
        'Decimal' => 'float',
        'Boolean' => 'bool',
        'DateTime' => '\DateTimeImmutable',
        'Date' => '\DateTimeImmutable',
    ];

    private const DEFAULT_PHP_TYPE = 'string';

    /**
     * @return PropertyDefinition[] in the order of the schema; interface and union fields are skipped
     */
    public function map(GraphqlSchema $schema, string $typeName): array
    {
        $fields = $schema->types[$typeName]['fields'] ?? [];
        $identifier = $this->identifierField($fields);
        $properties = [];

        foreach ($fields as $name => $field) {
            if (str_starts_with($name, '__')) {
                continue;
            }

            $property = match ($field['kind']) {
                'OBJECT' => $this->relation($schema, $name, $field),
                'SCALAR', 'ENUM' => $this->scalar($name, $field, $name === $identifier),
                default => null,
            };

            if ($property !== null) {
                $properties[] = $property;
            }
        }

        return $properties;
    }

    /**
     * @param array<string, SchemaField> $fields
     */
    private function identifierField(array $fields): ?string
    {
        if (($fields[self::IDENTIFIER_FIELD]['kind'] ?? null) === 'SCALAR') {
            return self::IDENTIFIER_FIELD;
        }

        foreach ($fields as $name => $field) {
            if ($field['name'] === self::IDENTIFIER_SCALAR) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param SchemaField $field
     */
    private function scalar(string $name, array $field, bool $identifier): PropertyDefinition
    {
        $list = $field['list'] ?? false;

        return new PropertyDefinition(
            name: $name,
            phpType: $list ? 'array' : (self::PHP_TYPES[$field['name'] ?? ''] ?? self::DEFAULT_PHP_TYPE),
            mappedFrom: $name,
            nullable: !$identifier && ($field['nullable'] ?? true),
            identifier: $identifier,
        );
    }

    /**
     * A list of objects, or a connection (Data API Builder), is a collection of the object type.
     *
     * @param SchemaField $field
     */
    private function relation(GraphqlSchema $schema, string $name, array $field): ?PropertyDefinition
    {
        $typeName = $field['name'];

        if ($typeName === null) {
            return null;
        }

        $itemType = $schema->connectionItemType($typeName);
        $collection = ($field['list'] ?? false) || $itemType !== null;
        $target = $itemType ?? $typeName;

        return new PropertyDefinition(
            name: $name,
            phpType: $collection ? 'array' : $target,
            mappedFrom: $name,
            nullable: !$collection,
            relation: true,
            collection: $collection,
            targetEntity: $target,
        );
    }
}
