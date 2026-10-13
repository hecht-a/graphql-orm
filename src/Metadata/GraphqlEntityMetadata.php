<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata;

/**
 * @internal
 */
final readonly class GraphqlEntityMetadata
{
    /**
     * @param class-string           $class
     * @param GraphqlFieldMetadata[] $fields
     */
    public function __construct(
        public string $class,
        public string $name,
        public ?string $repositoryClass,
        public array $fields,
        public ?GraphqlFieldMetadata $identifier = null,
    ) {
    }

    /**
     * The field named after its GraphQL name or, failing that, its PHP property: a property having the GraphQL name of
     * another field designates that field.
     */
    public function field(string $name): ?GraphqlFieldMetadata
    {
        foreach ($this->fields as $field) {
            if ($field->mappedFrom === $name) {
                return $field;
            }
        }

        foreach ($this->fields as $field) {
            if ($field->property === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The GraphQL name of a field named after its GraphQL name or its PHP property; an unknown name is kept as is.
     */
    public function graphqlName(string $name): string
    {
        return $this->field($name)->mappedFrom ?? $name;
    }

    /**
     * @template V
     *
     * @param array<string, V> $values keyed by GraphQL names or PHP properties
     *
     * @return array<string, V> keyed by GraphQL names
     */
    public function withGraphqlNames(array $values): array
    {
        $named = [];

        foreach ($values as $name => $value) {
            $named[$this->graphqlName($name)] = $value;
        }

        return $named;
    }
}
