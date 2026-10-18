<?php

declare(strict_types=1);

namespace GraphqlOrm\Codegen;

use GraphqlOrm\Schema\GraphqlSchema;
use GraphqlOrm\Schema\ScalarTypes;
use Symfony\Component\String\Inflector\EnglishInflector;

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
     * @param array<string, class-string> $scalarClasses the value object of the custom scalars a type converter converts
     */
    public function __construct(
        private array $scalarClasses = [],
    ) {
    }

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
        // A standard scalar keeps its PHP type, whichever converter tells it: Int stays int.
        $valueClass = !$list && !ScalarTypes::isKnown($field['name']) ? $this->scalarClasses[$field['name'] ?? ''] ?? null : null;

        return new PropertyDefinition(
            name: $name,
            phpType: match (true) {
                $list => 'array',
                $valueClass !== null => substr((string) strrchr('\\' . $valueClass, '\\'), 1),
                default => ScalarTypes::phpTypeOf($field['name']),
            },
            mappedFrom: $name,
            nullable: !$identifier && ($field['nullable'] ?? true),
            identifier: $identifier,
            valueClass: $valueClass,
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
        $target = $this->className($itemType ?? $typeName);

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

    /**
     * Types named after their table (Hasura: `users`, `task_categories`) become singular PascalCase class names; types
     * already named as classes (`Task`) are kept.
     */
    private function className(string $typeName): string
    {
        if (!ctype_lower($typeName[0])) {
            return $typeName;
        }

        $words = explode('_', $typeName);
        $last = array_pop($words);
        $words[] = (new EnglishInflector())->singularize($last)[0] ?? $last;

        return implode('', array_map(ucfirst(...), $words));
    }
}
