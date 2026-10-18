<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata;

/**
 * An #[GraphqlOrm\Embeddable] value object held by a field: its class, and its fields, a GraphQL object or prefixed
 * fields of the entity.
 *
 * @internal
 */
final readonly class GraphqlEmbeddedMetadata
{
    /**
     * @param class-string               $class
     * @param list<GraphqlFieldMetadata> $fields
     */
    public function __construct(
        public string $class,
        public ?string $prefix,
        public array $fields,
    ) {
    }

    /**
     * A field of the value object, named after its GraphQL name or its PHP property.
     */
    public function field(string $name): ?GraphqlFieldMetadata
    {
        foreach ($this->fields as $field) {
            if ($field->mappedFrom === $name || $field->property === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The name of a field of the value object in the response: prefixed for fields of the entity.
     */
    public function graphqlName(GraphqlFieldMetadata $field): string
    {
        return ($this->prefix ?? '') . $field->mappedFrom;
    }
}
