<?php

declare(strict_types=1);

namespace GraphqlOrm\PHPStan;

use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Checks field names against the entity mapping, read from the attributes as the bundle does at runtime.
 *
 * @internal
 */
final class FieldNameChecker
{
    private const MAX_SUGGESTION_DISTANCE = 3;

    /** @var array<string, GraphqlEntityMetadata|null> */
    private array $metadata = [];

    public function __construct(
        private readonly bool $reportUnmappedFields,
        private readonly GraphqlEntityMetadataFactory $metadataFactory = new GraphqlEntityMetadataFactory(),
    ) {
    }

    /**
     * A field name sent as is to the API (repository criteria, ordering): it must be the GraphQL name.
     *
     * @return list<IdentifierRuleError>
     */
    public function checkSentField(string $entityClass, string $name): array
    {
        $metadata = $this->metadata($entityClass);

        if ($metadata === null || $this->fieldByGraphqlName($metadata, $name) !== null) {
            return [];
        }

        $field = $this->fieldByProperty($metadata, $name);

        if ($field !== null) {
            return [RuleErrorBuilder::message(\sprintf('Field "%s" is the PHP property of %s: use its GraphQL name "%s".', $name, $entityClass, $field->mappedFrom))
                ->identifier('graphqlOrm.propertyName')
                ->build()];
        }

        return $this->unmapped($metadata, $name);
    }

    /**
     * A selected path (`user.name`), whose segments may be GraphQL or PHP property names.
     *
     * @return list<IdentifierRuleError>
     */
    public function checkSelectedPath(string $entityClass, string $path): array
    {
        $segments = explode('.', $path);
        $metadata = $this->metadata($entityClass);

        foreach ($segments as $index => $segment) {
            if ($metadata === null) {
                return [];
            }

            $field = $this->fieldByGraphqlName($metadata, $segment) ?? $this->fieldByProperty($metadata, $segment);

            if ($field === null) {
                return $this->unmapped($metadata, $segment);
            }

            if ($index === \count($segments) - 1) {
                return [];
            }

            if ($field->relation === null) {
                return [RuleErrorBuilder::message(\sprintf('Field "%s" of %s is not a relation: "%s" cannot be selected.', $segment, $metadata->class, $path))
                    ->identifier('graphqlOrm.notARelation')
                    ->build()];
            }

            $metadata = $this->metadata($field->relation);
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function unmapped(GraphqlEntityMetadata $metadata, string $name): array
    {
        if (!$this->reportUnmappedFields) {
            return [];
        }

        $suggestion = $this->suggest($name, array_map(fn (GraphqlFieldMetadata $field) => $field->mappedFrom, $metadata->fields));

        return [RuleErrorBuilder::message(\sprintf('Field "%s" is not mapped on %s.%s', $name, $metadata->class, $suggestion !== null ? \sprintf(' Did you mean "%s"?', $suggestion) : ''))
            ->identifier('graphqlOrm.unmappedField')
            ->tip('A field of the API that the entity does not map can be allowed with @phpstan-ignore graphqlOrm.unmappedField, or every such field with the graphqlOrm.reportUnmappedFields parameter.')
            ->build()];
    }

    /**
     * Metadata of an entity, or null when it cannot be read (not an entity, class not loadable): nothing is reported.
     */
    private function metadata(string $class): ?GraphqlEntityMetadata
    {
        if (!\array_key_exists($class, $this->metadata)) {
            try {
                $this->metadata[$class] = class_exists($class) ? $this->metadataFactory->getMetadata($class) : null;
            } catch (\Throwable) {
                $this->metadata[$class] = null;
            }
        }

        return $this->metadata[$class];
    }

    private function fieldByGraphqlName(GraphqlEntityMetadata $metadata, string $name): ?GraphqlFieldMetadata
    {
        foreach ($metadata->fields as $field) {
            if ($field->mappedFrom === $name) {
                return $field;
            }
        }

        return null;
    }

    private function fieldByProperty(GraphqlEntityMetadata $metadata, string $name): ?GraphqlFieldMetadata
    {
        foreach ($metadata->fields as $field) {
            if ($field->property === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param string[] $candidates
     */
    private function suggest(string $name, array $candidates): ?string
    {
        $best = null;
        $bestDistance = self::MAX_SUGGESTION_DISTANCE + 1;

        foreach ($candidates as $candidate) {
            $distance = levenshtein($name, $candidate);

            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }
}
