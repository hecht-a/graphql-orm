<?php

declare(strict_types=1);

namespace GraphqlOrm\Schema;

/**
 * The types of an introspected schema, with the lookups shared by schema validation and entity generation.
 *
 * @phpstan-type SchemaField array{kind: string, name: string|null, nullable?: bool, list?: bool}
 * @phpstan-type SchemaType array{kind: string, fields: array<string, SchemaField>}
 *
 * @internal
 */
final readonly class GraphqlSchema
{
    public const DEFAULT_QUERY_TYPE = 'Query';

    /**
     * Field wrapping the entities of a paginated field (e.g. Data API Builder connections).
     */
    private const CONNECTION_ITEMS_FIELD = 'items';

    /**
     * @param array<string, SchemaType> $types
     */
    public function __construct(
        public array $types,
        public string $queryType = self::DEFAULT_QUERY_TYPE,
    ) {
    }

    /**
     * Finds the type of the entities returned by a root query field: the type of the field, unwrapped from its
     * connection if any, or a type named after the root (`tasks` => `Task`) when the field is unknown.
     */
    public function entityTypeOfRoot(string $rootName): ?string
    {
        $rootType = $this->types[$this->queryType]['fields'][$rootName]['name'] ?? null;

        if ($rootType !== null && $this->isObject($rootType)) {
            return $this->connectionItemType($rootType) ?? $rootType;
        }

        foreach ([ucfirst($rootName), ucfirst(rtrim($rootName, 's')), $rootName] as $candidate) {
            if ($this->isObject($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The type of the `items` of a connection type, or null when the type is not a connection.
     */
    public function connectionItemType(string $typeName): ?string
    {
        $itemType = $this->types[$typeName]['fields'][self::CONNECTION_ITEMS_FIELD]['name'] ?? null;

        return $itemType !== null && $this->isObject($itemType) ? $itemType : null;
    }

    public function isObject(string $typeName): bool
    {
        return ($this->types[$typeName]['kind'] ?? null) === 'OBJECT';
    }
}
