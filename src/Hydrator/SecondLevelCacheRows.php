<?php

declare(strict_types=1);

namespace GraphqlOrm\Hydrator;

use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;

/**
 * The rows the hydration keeps in the second-level cache, for the entities marked #[GraphqlOrm\Cache].
 *
 * @internal
 */
final class SecondLevelCacheRows
{
    /**
     * Keeps the row of an entity marked #[GraphqlOrm\Cache] in the second-level cache, its relations reduced to their
     * identifier: they are loaded on first access when it is read back. Not kept when a related entity has no
     * identifier.
     *
     * @param array<string|int, mixed> $data
     */
    public static function keep(GraphqlEntityMetadataFactory $metadataFactory, GraphqlEntityMetadata $metadata, array $data, GraphqlExecutionContext $context): void
    {
        $identifier = $metadata->uniqueField();
        $value = $identifier !== null ? ($data[$identifier->mappedFrom] ?? null) : null;

        if ($context->entityCache === null || (!\is_int($value) && !\is_string($value)) || !$context->entityCache->caches($metadata->class)) {
            return;
        }

        $kept = $metadata->class . '#' . $value;

        if (isset($context->keptInCache[$kept])) {
            return;
        }

        $row = [];

        foreach ($metadata->fields as $field) {
            $fieldValue = $data[$field->mappedFrom];

            if ($field->relation === null || !\is_array($fieldValue)) {
                $row[$field->mappedFrom] = $fieldValue;

                continue;
            }

            $targetIdentifier = $metadataFactory->getMetadata($field->relation)->uniqueField()?->mappedFrom;

            if ($targetIdentifier === null) {
                return;
            }

            $reduce = static fn (mixed $related): ?array => \is_array($related) && \array_key_exists($targetIdentifier, $related) ? [$targetIdentifier => $related[$targetIdentifier]] : null;
            $reduced = $field->isCollection ? array_map($reduce, $context->dialect->extractCollection($fieldValue)) : $reduce($fieldValue);

            if ($reduced === null || \in_array(null, $field->isCollection ? $reduced : [], true)) {
                return;
            }

            $row[$field->mappedFrom] = $reduced;
        }

        $context->entityCache->putEntityRow($metadata->class, $value, $row);
        $context->keptInCache[$kept] = true;
    }
}
