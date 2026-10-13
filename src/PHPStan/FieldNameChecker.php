<?php

declare(strict_types=1);

namespace GraphqlOrm\PHPStan;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\Naming\DefaultNamingStrategy;
use GraphqlOrm\Metadata\Naming\NamingStrategy;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Checks field names against the entity mapping, read from the attributes as the bundle does at runtime.
 *
 * @internal
 */
final class FieldNameChecker
{
    /** @var array<string, GraphqlEntityMetadata|null> */
    private array $metadata = [];

    private readonly GraphqlEntityMetadataFactory $metadataFactory;

    /**
     * @param string $namingStrategy class of the NamingStrategy of the application, built without argument
     *
     * @throws InvalidArgumentException when the class is not a naming strategy
     */
    public function __construct(
        private readonly bool $reportUnmappedFields,
        string $namingStrategy = DefaultNamingStrategy::class,
    ) {
        if (!is_subclass_of($namingStrategy, NamingStrategy::class)) {
            throw new InvalidArgumentException(\sprintf('The graphqlOrm.namingStrategy parameter must be a class implementing %s, "%s" given.', NamingStrategy::class, $namingStrategy));
        }

        $this->metadataFactory = new GraphqlEntityMetadataFactory(namingStrategy: new $namingStrategy());
    }

    /**
     * A field name of the repository criteria or of an ordering: its GraphQL name or its PHP property.
     *
     * @return list<IdentifierRuleError>
     */
    public function checkSentField(string $entityClass, string $name): array
    {
        $metadata = $this->metadata($entityClass);

        if ($metadata === null || $metadata->field($name) !== null) {
            return [];
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

            $field = $metadata->field($segment);

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

        $suggestion = $metadata->closestField($name);

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
}
