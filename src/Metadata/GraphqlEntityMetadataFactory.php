<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Exception\NotAGraphqlEntityException;
use GraphqlOrm\Exception\TooManyIdentifiersException;
use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;
use GraphqlOrm\Metadata\Naming\DefaultNamingStrategy;
use GraphqlOrm\Metadata\Naming\NamingStrategy;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolverInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * @internal
 */
class GraphqlEntityMetadataFactory
{
    private const CACHE_KEY_PREFIX = 'graphql_orm.metadata.';

    /**
     * @var array<string, GraphqlEntityMetadata>
     */
    private array $loaded = [];

    private readonly TypeResolverInterface $typeResolver;

    /**
     * @param CacheInterface|null $cache persistent cache, so that attributes and docblocks are not read on every request
     */
    public function __construct(
        ?TypeResolverInterface $typeResolver = null,
        private readonly ?CacheInterface $cache = null,
        private readonly NamingStrategy $namingStrategy = new DefaultNamingStrategy(),
    ) {
        $this->typeResolver = $typeResolver ?? TypeResolver::create();
    }

    /**
     * @param class-string $class
     *
     * @throws \ReflectionException
     * @throws NotAGraphqlEntityException  when the class has no #[Entity] attribute
     * @throws TooManyIdentifiersException when several fields are marked as identifier
     */
    public function getMetadata(string $class): GraphqlEntityMetadata
    {
        if (isset($this->loaded[$class])) {
            return $this->loaded[$class];
        }

        if ($this->cache === null) {
            return $this->loaded[$class] = $this->build($class);
        }

        // A failed build throws before anything is stored, so invalid entities are never cached.
        return $this->loaded[$class] = $this->cache->get(self::CACHE_KEY_PREFIX . str_replace('\\', '.', $class), fn () => $this->build($class));
    }

    /**
     * @param class-string $class
     *
     * @throws \ReflectionException
     * @throws NotAGraphqlEntityException  when the class has no #[Entity] attribute
     * @throws TooManyIdentifiersException when several fields are marked as identifier
     */
    private function build(string $class): GraphqlEntityMetadata
    {
        $reflection = new \ReflectionClass($class);

        $entityAttr = $reflection->getAttributes(Entity::class)[0] ?? null;

        if (!$entityAttr) {
            throw NotAGraphqlEntityException::forClass($class);
        }

        $entity = $entityAttr->newInstance();

        $fields = [];
        $identifier = null;

        foreach ($reflection->getProperties() as $property) {
            $field = $this->field($class, $property);

            if ($field === null) {
                continue;
            }

            if ($field->isIdentifier) {
                if ($identifier !== null) {
                    throw TooManyIdentifiersException::forClass($class);
                }

                $identifier = $field;
            }

            $fields[] = $field;
        }

        return new GraphqlEntityMetadata(
            $class,
            $entity->name,
            $entity->repositoryClass,
            $fields,
            $identifier
        );
    }

    /**
     * The field mapped on a property with #[Field] or #[Relation], if any.
     *
     * @param class-string $class
     *
     * @throws InvalidArgumentException when the attributes of the property contradict each other
     */
    private function field(string $class, \ReflectionProperty $property): ?GraphqlFieldMetadata
    {
        $value = $property->getAttributes(Field::class)[0] ?? null;
        $relation = $property->getAttributes(Relation::class)[0] ?? null;
        $isIdentifier = $property->getAttributes(Identifier::class) !== [];
        $name = $class . '::$' . $property->getName();

        if ($isIdentifier && $value === null) {
            throw new InvalidArgumentException(\sprintf('"%s" has #[Identifier] without #[Field]: the identifier is a field holding a value, declare it with #[Field].', $name));
        }

        if ($value !== null && $relation !== null) {
            throw new InvalidArgumentException(\sprintf('"%s" is mapped with both #[Field] and #[Relation]: map a relation with #[Relation] only.', $name));
        }

        return match (true) {
            $relation !== null => $this->relationField($name, $property, $relation->newInstance()),
            $value !== null => $this->valueField($name, $property, $value->newInstance(), $isIdentifier),
            default => null,
        };
    }

    /**
     * @throws InvalidArgumentException when the type of the property is no entity
     */
    private function relationField(string $name, \ReflectionProperty $property, Relation $attribute): GraphqlFieldMetadata
    {
        [$relation, $isCollection] = $this->resolveRelation($property, $attribute->targetEntity);

        if ($relation === null) {
            throw new InvalidArgumentException(\sprintf('"%s" is mapped with #[Relation] but its type is no entity: map a value with #[Field], or give the targetEntity of the relation.', $name));
        }

        return new GraphqlFieldMetadata(
            property: $property->getName(),
            mappedFrom: $attribute->mappedFrom ?? $this->namingStrategy->fieldName($property->getName()),
            relation: $relation,
            isCollection: $isCollection,
            ignoreValidation: $attribute->ignoreValidation,
            fetch: $attribute->fetch,
        );
    }

    /**
     * @throws InvalidArgumentException when the type of the property is an entity
     */
    private function valueField(string $name, \ReflectionProperty $property, Field $attribute, bool $isIdentifier): GraphqlFieldMetadata
    {
        if ($this->resolveRelation($property, null)[0] !== null) {
            throw new InvalidArgumentException(\sprintf('"%s" holds an entity: map a relation with #[Relation], not #[Field].', $name));
        }

        return new GraphqlFieldMetadata(
            property: $property->getName(),
            mappedFrom: $attribute->mappedFrom ?? $this->namingStrategy->fieldName($property->getName()),
            isIdentifier: $isIdentifier,
            ignoreValidation: $attribute->ignoreValidation,
        );
    }

    /**
     * @param class-string|null $targetEntity
     *
     * @return array{class-string|null, bool} the relation target and whether it is a collection
     */
    private function resolveRelation(\ReflectionProperty $property, ?string $targetEntity): array
    {
        $types = $this->namedTypes($property->getType());
        $isArray = \in_array('array', array_map(fn (\ReflectionNamedType $type) => $type->getName(), $types), true);

        if ($targetEntity !== null) {
            return [$targetEntity, $isArray];
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

    /**
     * Whether a class is mapped as an entity, with #[Entity].
     */
    public function isEntityClass(string $class): bool
    {
        return class_exists($class) && (new \ReflectionClass($class))->getAttributes(Entity::class) !== [];
    }

    private function isEntity(string $class): bool
    {
        return $this->isEntityClass($class);
    }
}
