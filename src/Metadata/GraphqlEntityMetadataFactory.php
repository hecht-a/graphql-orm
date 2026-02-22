<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Exception\NotAGraphqlEntityException;
use GraphqlOrm\Exception\TooManyIdentifiersException;

/**
 * @template T of object
 */
class GraphqlEntityMetadataFactory
{
    /**
     * @var array<string, GraphqlEntityMetadata>
     */
    private array $cache = [];

    /**
     * @param class-string $class
     *
     * @throws \ReflectionException
     */
    public function getMetadata(string $class): GraphqlEntityMetadata
    {
        if (isset($this->cache[$class])) {
            return $this->cache[$class];
        }

        $reflection = new \ReflectionClass($class);

        $entityAttr = $reflection->getAttributes(GraphqlEntity::class)[0] ?? null;

        if (!$entityAttr) {
            throw NotAGraphqlEntityException::forClass($class);
        }

        /** @var GraphqlEntity<T> $entity */
        $entity = $entityAttr->newInstance();

        $fields = [];
        $identifier = null;

        foreach ($reflection->getProperties() as $property) {
            $attr = $property->getAttributes(GraphqlField::class)[0] ?? null;

            if (!$attr) {
                continue;
            }

            /** @var GraphqlField $instance */
            $instance = $attr->newInstance();
            $relation = $instance->targetEntity;
            $isCollection = false;

            $type = $property->getType();

            if ($relation === null && $type !== null) {
                $types = match (true) {
                    $type instanceof \ReflectionNamedType => [$type],
                    $type instanceof \ReflectionUnionType => $type->getTypes(),
                    default => [],
                };

                /** @var \ReflectionNamedType $namedType */
                foreach ($types as $namedType) {
                    if ($namedType->isBuiltin()) {
                        continue;
                    }

                    $typeName = $namedType->getName();

                    if (!class_exists($typeName)) {
                        continue;
                    }

                    $targetReflection = new \ReflectionClass($typeName);

                    if ($targetReflection->getAttributes(GraphqlEntity::class)) {
                        $relation = $typeName;
                        break;
                    }
                }
            } else {
                if ($type === null) {
                    continue;
                }

                /** @var \ReflectionNamedType $type */
                if ($type->getName() === 'array') {
                    $isCollection = true;
                }
            }

            $field = new GraphqlFieldMetadata(
                property: $property->getName(),
                mappedFrom: $instance->mappedFrom,
                relation: $relation,
                isCollection: $isCollection,
                isIdentifier: $instance->identifier
            );

            if ($field->isIdentifier) {
                if ($identifier !== null) {
                    throw TooManyIdentifiersException::forClass($class);
                }

                $identifier = $field;
            }

            $fields[] = $field;
        }

        return $this->cache[$class] =
            new GraphqlEntityMetadata(
                $class,
                $entity->name,
                $entity->repositoryClass,
                $fields,
                $identifier
            );
    }
}
