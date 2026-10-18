<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata;

use GraphqlOrm\Exception\InvalidArgumentException;

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
    /**
     * The PHP property of a field named after its GraphQL name or its PHP property.
     *
     * @throws InvalidArgumentException when the entity maps no such field
     */
    public function property(string $name): string
    {
        $field = $this->field($name);

        if ($field !== null) {
            return $field->property;
        }

        $closest = $this->closestField($name);

        throw new InvalidArgumentException(\sprintf('"%s" is not a field of "%s"%s.', $name, $this->class, $closest !== null ? \sprintf(': did you mean "%s"?', $closest) : ''));
    }

    /**
     * The GraphQL name of a field named after its GraphQL name or its PHP property, or of a field of a value object of
     * prefixed fields (`total.amount` gives `total_amount`); an unknown name is kept as is.
     *
     * @throws InvalidArgumentException for a field of a value object nested in a GraphQL object, which the ordering and
     *                                  the criteria of the dialects do not name
     */
    public function graphqlName(string $name): string
    {
        $field = $this->field($name);

        if ($field !== null || !str_contains($name, '.')) {
            return $field->mappedFrom ?? $name;
        }

        [$embedded, $embeddedField] = $this->embeddedField($name) ?? [null, null];

        if ($embedded === null || $embeddedField === null) {
            return $name;
        }

        if ($embedded->embedded?->prefix === null) {
            throw new InvalidArgumentException(\sprintf('"%s" is a field of the value object "%s", a GraphQL object: filter on it with the query builder, the ordering and the criteria name fields of the entity.', $name, $embedded->property));
        }

        return $embedded->embedded->graphqlName($embeddedField);
    }

    /**
     * The field of a value object named by its path (`total.amount`), with the field holding the value object, or a
     * field of the entity.
     */
    public function valueField(string $name): ?GraphqlFieldMetadata
    {
        return $this->field($name) ?? ($this->embeddedField($name)[1] ?? null);
    }

    /**
     * @return array{GraphqlFieldMetadata, GraphqlFieldMetadata}|null the field holding the value object, and its field
     */
    private function embeddedField(string $path): ?array
    {
        [$name, $embeddedName] = explode('.', $path, 2) + [1 => ''];
        $field = $this->field($name);
        $embeddedField = $field?->embedded?->field($embeddedName);

        return $field !== null && $embeddedField !== null ? [$field, $embeddedField] : null;
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
