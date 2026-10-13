<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata;

/**
 * @internal
 */
final readonly class GraphqlEntityMetadata
{
    private const MAX_SUGGESTION_DISTANCE = 3;

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
     * The GraphQL name or PHP property closest to a name that matches no field, if one is close enough to be a typo.
     */
    public function closestField(string $name): ?string
    {
        $best = null;
        $bestDistance = self::MAX_SUGGESTION_DISTANCE + 1;

        foreach ($this->fields as $field) {
            foreach ([$field->mappedFrom, $field->property] as $candidate) {
                $distance = levenshtein($name, $candidate);

                if ($distance < $bestDistance) {
                    $best = $candidate;
                    $bestDistance = $distance;
                }
            }
        }

        return $best;
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
