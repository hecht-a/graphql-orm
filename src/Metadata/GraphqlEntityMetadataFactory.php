<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Exception\NotAGraphqlEntityException;
use GraphqlOrm\Exception\TooManyIdentifiersException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolverInterface;

/**
 * @template T of object
 */
class GraphqlEntityMetadataFactory
{
    /**
     * @var array<string, GraphqlEntityMetadata>
     */
    private array $cache = [];

    private readonly TypeResolverInterface $typeResolver;

    public function __construct(?TypeResolverInterface $typeResolver = null)
    {
        $this->typeResolver = $typeResolver ?? TypeResolver::create();
    }

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
            [$relation, $isCollection] = $this->resolveRelation($property, $instance);

            $field = new GraphqlFieldMetadata(
                property: $property->getName(),
                mappedFrom: $instance->mappedFrom,
                relation: $relation,
                isCollection: $isCollection,
                isIdentifier: $instance->identifier,
                ignoreValidation: $instance->ignoreValidation
            );

            if ($field->isIdentifier) {
                if ($identifier !== null) {
                    throw TooManyIdentifiersException::forClass($class);
                }

                $identifier = $field;
            }

            $fields[] = $field;
        }

        return $this->cache[$class] = new GraphqlEntityMetadata(
            $class,
            $entity->name,
            $entity->repositoryClass,
            $fields,
            $identifier
        );
    }

    /**
     * @return array{class-string|null, bool} the relation target and whether it is a collection
     */
    private function resolveRelation(\ReflectionProperty $property, GraphqlField $attribute): array
    {
        $types = $this->namedTypes($property->getType());
        $isArray = \in_array('array', array_map(fn (\ReflectionNamedType $type) => $type->getName(), $types), true);

        if ($attribute->targetEntity !== null) {
            return [$attribute->targetEntity, $isArray];
        }

        foreach ($types as $type) {
            if (!$type->isBuiltin() && $this->isEntity($type->getName())) {
                /** @var class-string $relation */
                $relation = $type->getName();

                return [$relation, false];
            }
        }

        if ($isArray) {
            $target = $this->resolveCollectionTarget($property);

            return [$target, $target !== null];
        }

        return [null, false];
    }

    /**
     * @return \ReflectionNamedType[]
     */
    private function namedTypes(?\ReflectionType $type): array
    {
        return match (true) {
            $type instanceof \ReflectionNamedType => [$type],
            $type instanceof \ReflectionUnionType => array_filter(
                $type->getTypes(),
                fn ($type) => $type instanceof \ReflectionNamedType
            ),
            default => [],
        };
    }

    /**
     * Reads the collection item type from the property docblock (e.g. `@var Task[]`, `@var list<Task>`).
     *
     * @return class-string|null
     */
    private function resolveCollectionTarget(\ReflectionProperty $property): ?string
    {
        try {
            $type = $this->typeResolver->resolve($property);
        } catch (UnsupportedException) {
            return null;
        }

        foreach ($type->traverse() as $candidate) {
            if (!$candidate instanceof CollectionType) {
                continue;
            }

            $valueType = $candidate->getCollectionValueType();

            if ($valueType instanceof ObjectType && $this->isEntity($valueType->getClassName())) {
                /** @var class-string $target */
                $target = $valueType->getClassName();

                return $target;
            }
        }

        return null;
    }

    private function isEntity(string $class): bool
    {
        return class_exists($class)
            && (new \ReflectionClass($class))->getAttributes(GraphqlEntity::class) !== [];
    }
}
