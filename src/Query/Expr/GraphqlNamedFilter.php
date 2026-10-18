<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Expr;

use GraphqlOrm\Converter\TypeConverterRegistry;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;

/**
 * A filter whose fields, named after their GraphQL name or their PHP property, are given their GraphQL name. A path
 * through relations (`user.name`) is nested, as GraphQL filters are: `{user: {name: {eq: ...}}}`. The value objects
 * compared are converted by their type converter.
 *
 * @internal
 */
final readonly class GraphqlNamedFilter implements FilterExpressionInterface
{
    private const LOGICAL_OPERATORS = ['and', 'or'];

    public function __construct(
        private FilterExpressionInterface $filter,
        private GraphqlEntityMetadata $metadata,
        private GraphqlEntityMetadataFactory $metadataFactory,
        private TypeConverterRegistry $typeConverters = new TypeConverterRegistry(),
    ) {
    }

    public function toArray(): array
    {
        return $this->rename($this->filter->toArray());
    }

    /**
     * The fields a filter names, as written.
     *
     * @param array<mixed> $filter `{field: {operator: value}}` or `{and|or: [filter, ...]}`
     *
     * @return list<string>
     */
    public static function fieldNames(array $filter): array
    {
        $names = [];

        foreach ($filter as $key => $value) {
            $key = (string) $key;

            if (\in_array($key, self::LOGICAL_OPERATORS, true) && \is_array($value)) {
                foreach ($value as $operand) {
                    $names = [...$names, ...(\is_array($operand) ? self::fieldNames($operand) : [])];
                }

                continue;
            }

            $names[] = $key;
        }

        return $names;
    }

    /**
     * @param array<mixed> $filter `{field: {operator: value}}` or `{and|or: [filter, ...]}`
     *
     * @return array<string, mixed>
     */
    private function rename(array $filter): array
    {
        $renamed = [];

        foreach ($filter as $key => $value) {
            $key = (string) $key;

            if (\in_array($key, self::LOGICAL_OPERATORS, true) && \is_array($value)) {
                $renamed[$key] = array_map(fn (mixed $operand) => \is_array($operand) ? $this->rename($operand) : $operand, $value);

                continue;
            }

            $renamed = [...$renamed, ...$this->nest($this->metadata, explode('.', $key), $value)];
        }

        return $renamed;
    }

    /**
     * @param non-empty-list<string> $segments the field path, its segments being GraphQL names or PHP properties
     *
     * @return array<string, mixed>
     */
    private function nest(?GraphqlEntityMetadata $metadata, array $segments, mixed $comparison): array
    {
        $segment = array_shift($segments);
        $field = $metadata?->field($segment);
        $name = $field->mappedFrom ?? $segment;

        if ($segments === []) {
            return [$name => $this->typeConverters->toGraphql($field?->converter, $field?->valueClass, $comparison)];
        }

        // A field of a value object: a field of the entity when prefixed, of its GraphQL object otherwise.
        $embeddedField = \count($segments) === 1 ? $field?->embedded?->field($segments[0]) : null;

        if ($field?->embedded !== null && $embeddedField !== null) {
            $converted = $this->typeConverters->toGraphql($embeddedField->converter, $embeddedField->valueClass, $comparison);

            return $field->embedded->prefix !== null
                ? [$field->embedded->graphqlName($embeddedField) => $converted]
                : [$name => [$embeddedField->mappedFrom => $converted]];
        }

        // An unmapped segment, or one that is no relation, is nested as written: the API tells whether it exists.
        $target = $field?->relation !== null ? $this->metadataFactory->getMetadata($field->relation) : null;

        return [$name => $this->nest($target, $segments, $comparison)];
    }
}
