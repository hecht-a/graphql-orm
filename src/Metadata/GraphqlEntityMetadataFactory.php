<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata;

use Doctrine\Common\Collections\Collection;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Exception\NotAGraphqlEntityException;
use GraphqlOrm\Exception\TooManyIdentifiersException;
use GraphqlOrm\Mapping\Embeddable;
use GraphqlOrm\Mapping\Embedded;
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
final class GraphqlEntityMetadataFactory
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
     * @throws NotAGraphqlEntityException  when the class has no #[GraphqlOrm\Entity] attribute
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
     * @throws NotAGraphqlEntityException  when the class has no #[GraphqlOrm\Entity] attribute
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
            $identifier,
            $entity->type ?? $reflection->getShortName(),
        );
    }

    /**
     * The field mapped on a property with #[GraphqlOrm\Field] or #[GraphqlOrm\Relation], if any.
     *
     * @param class-string $class
     *
     * @throws InvalidArgumentException when the attributes of the property contradict each other
     */
    private function field(string $class, \ReflectionProperty $property): ?GraphqlFieldMetadata
    {
        $value = $property->getAttributes(Field::class)[0] ?? null;
        $relation = $property->getAttributes(Relation::class)[0] ?? null;
        $embedded = $property->getAttributes(Embedded::class)[0] ?? null;
        $isIdentifier = $property->getAttributes(Identifier::class) !== [];
        $name = $class . '::$' . $property->getName();

        if ($isIdentifier && $value === null) {
            throw new InvalidArgumentException(\sprintf('"%s" has #[GraphqlOrm\Identifier] without #[GraphqlOrm\Field]: the identifier is a field holding a value, declare it with #[GraphqlOrm\Field].', $name));
        }

        if ($value !== null && $relation !== null) {
            throw new InvalidArgumentException(\sprintf('"%s" is mapped with both #[GraphqlOrm\Field] and #[GraphqlOrm\Relation]: map a relation with #[GraphqlOrm\Relation] only.', $name));
        }

        // #[GraphqlOrm\Identifier] needs #[GraphqlOrm\Field]: refused with #[GraphqlOrm\Embedded] as well.
        if ($embedded !== null && ($value !== null || $relation !== null)) {
            throw new InvalidArgumentException(\sprintf('"%s" is mapped with #[GraphqlOrm\Embedded] and #[GraphqlOrm\Field], #[GraphqlOrm\Relation] or #[GraphqlOrm\Identifier]: an embedded value object is mapped with #[GraphqlOrm\Embedded] only.', $name));
        }

        return match (true) {
            $embedded !== null => $this->embeddedField($name, $property, $embedded->newInstance()),
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
            throw new InvalidArgumentException(\sprintf('"%s" is mapped with #[GraphqlOrm\Relation] but its type is no entity: map a value with #[GraphqlOrm\Field], or give the targetEntity of the relation.', $name));
        }

        return new GraphqlFieldMetadata(
            property: $property->getName(),
            mappedFrom: $attribute->mappedFrom ?? $this->namingStrategy->fieldName($property->getName()),
            relation: $relation,
            isCollection: $isCollection,
            ignoreValidation: $attribute->ignoreValidation,
            fetch: $attribute->fetch,
            joinField: $attribute->joinField,
        );
    }

    /**
     * @throws InvalidArgumentException when the type of the property is no #[GraphqlOrm\Embeddable] class
     */
    private function embeddedField(string $name, \ReflectionProperty $property, Embedded $attribute): GraphqlFieldMetadata
    {
        $class = self::valueClass($property->getType());

        if ($class === null || (new \ReflectionClass($class))->getAttributes(Embeddable::class) === []) {
            throw new InvalidArgumentException(\sprintf('"%s" is mapped with #[GraphqlOrm\Embedded] but "%s" has no #[GraphqlOrm\Embeddable].', $name, $class ?? (string) $property->getType()));
        }

        $fields = [];

        foreach ((new \ReflectionClass($class))->getProperties() as $embeddableProperty) {
            $field = $embeddableProperty->getAttributes(Field::class)[0] ?? null;

            if ($field !== null) {
                $fields[] = $this->valueField($class . '::$' . $embeddableProperty->getName(), $embeddableProperty, $field->newInstance(), false);
            }
        }

        return new GraphqlFieldMetadata(
            property: $property->getName(),
            mappedFrom: $attribute->mappedFrom ?? $this->namingStrategy->fieldName($property->getName()),
            valueClass: $class,
            embedded: new GraphqlEmbeddedMetadata($class, $attribute->prefix, $fields),
        );
    }

    /**
     * @throws InvalidArgumentException when the type of the property is an entity
     */
    private function valueField(string $name, \ReflectionProperty $property, Field $attribute, bool $isIdentifier): GraphqlFieldMetadata
    {
        if ($this->resolveRelation($property, null)[0] !== null) {
            throw new InvalidArgumentException(\sprintf('"%s" holds an entity: map a relation with #[GraphqlOrm\Relation], not #[GraphqlOrm\Field].', $name));
        }

        return new GraphqlFieldMetadata(
            property: $property->getName(),
            mappedFrom: $attribute->mappedFrom ?? $this->namingStrategy->fieldName($property->getName()),
            isIdentifier: $isIdentifier,
            ignoreValidation: $attribute->ignoreValidation,
            converter: $attribute->converter,
            valueClass: self::valueClass($property->getType()),
        );
    }

    /**
     * The class of a type, nullable or not: a value object, a date, an enum.
     *
     * @return class-string|null
     */
    public static function valueClass(?\ReflectionType $type): ?string
    {
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        /** @var class-string $class */
        $class = $type->getName();

        return $class;
    }

    /**
     * @param class-string|null $targetEntity
     *
     * @return array{class-string|null, bool} the relation target and whether it is a collection
     */
    private function resolveRelation(\ReflectionProperty $property, ?string $targetEntity): array
    {
        $types = $this->namedTypes($property->getType());
        $isArray = array_filter($types, fn (\ReflectionNamedType $type) => $type->getName() === 'array' || self::isCollectionClass($type->getName())) !== [];

        if ($targetEntity !== null) {
            return [$targetEntity, $isArray];
        }

        foreach ($types as $type) {
            if (!$type->isBuiltin() && !self::isCollectionClass($type->getName()) && $this->isEntity($type->getName())) {
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
     * Whether the type is a collection of doctrine/collections, e.g. Collection<int, Task>: a list of entities as an
     * array.
     */
    private static function isCollectionClass(string $type): bool
    {
        return interface_exists(Collection::class) && is_a($type, Collection::class, true);
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
     * Whether a class is mapped as an entity, with #[GraphqlOrm\Entity].
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
