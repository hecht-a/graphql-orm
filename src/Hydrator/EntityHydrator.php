<?php

declare(strict_types=1);

namespace GraphqlOrm\Hydrator;

use Doctrine\Common\Collections\ArrayCollection;
use GraphqlOrm\Attribute\AfterHydrate;
use GraphqlOrm\Attribute\BeforeHydrate;
use GraphqlOrm\Exception\EntityNotFoundException;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Exception\PartialEntityException;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Mapping\Fetch;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;

/**
 * @internal
 */
final class EntityHydrator
{
    /**
     * Field GraphQL servers may add to any object, ignored when telling whether a row holds more than the identifier.
     */
    private const TYPENAME_FIELD = '__typename';

    /**
     * Reflection is resolved once per class, not once per hydrated row.
     *
     * @var array<string, array<string, \ReflectionProperty>>
     */
    private array $properties = [];

    /**
     * @var array<string, \ReflectionClass<object>>
     */
    private array $classes = [];

    /**
     * @var array<string, array<class-string, \ReflectionMethod[]>>
     */
    private array $hookMethods = [];

    /** @var array<string, list<\ReflectionProperty>> the properties of the objects of each class, copied to its ghosts */
    private array $instanceProperties = [];

    /**
     * @var array<string, array<string, array{\ReflectionType|null, bool}>> by class and property: its type, read for each
     *                                                                      value, and whether an ArrayCollection fits it
     */
    private array $types = [];

    /**
     * @var \WeakMap<object, list<string>> the entities hydrated from a selection of their fields, with the properties
     *                                     the query did not select; they are kept out of the lazy loads
     */
    private \WeakMap $partial;

    public function __construct(
        private readonly GraphqlEntityMetadataFactory $metadataFactory,
    ) {
        $this->partial = new \WeakMap();
    }

    /**
     * The mapped properties the query hydrating the entity did not select, whatever their default value: empty for an
     * entity hydrated in full, or completed since.
     *
     * @return list<string>
     */
    public function unselectedProperties(object $entity): array
    {
        return $this->partial[$entity] ?? [];
    }

    /**
     * An entity of which only the identifier is set, loaded on first access with the lazy loader of the context: the
     * entity the context already knows, if any.
     */
    public function reference(GraphqlEntityMetadata $metadata, int|string $identifier, GraphqlExecutionContext $context, ?string $relation = null): object
    {
        $field = $metadata->identifier ?? throw new LogicException(\sprintf('"%s" has no identifier to be referenced: mark its identifier field with #[GraphqlOrm\Identifier].', $metadata->class));

        return $this->hydrateEntity($metadata, [$field->mappedFrom => $identifier], $context, 0, lazy: true, relation: $relation);
    }

    /**
     * @param array<string|int, mixed> $data
     */
    public function hydrate(
        GraphqlEntityMetadata $metadata,
        array $data,
        GraphqlExecutionContext $context,
        int $depth = 0,
    ): object {
        return $this->hydrateEntity($metadata, $data, $context, $depth, lazy: false);
    }

    /**
     * @param array<string|int, mixed> $data
     * @param bool                     $lazy whether the relation holding this row is fetched lazily
     */
    private function hydrateEntity(
        GraphqlEntityMetadata $metadata,
        array $data,
        GraphqlExecutionContext $context,
        int $depth,
        bool $lazy,
        ?string $relation = null,
    ): object {
        $context->trace->hydrationMaxDepth = max(
            $context->trace->hydrationMaxDepth,
            $depth
        );
        $data = self::embeddedNested($metadata, $data);

        $identifier = $this->findUniqueField($metadata);
        $idField = $identifier?->mappedFrom;
        $uniqueField = $idField !== null ? ($data[$idField] ?? null) : null;

        if (!\is_int($uniqueField) && !\is_string($uniqueField)) {
            $uniqueField = null;
        }

        $known = $uniqueField !== null ? $context->knownEntity($metadata->class, $uniqueField) : null;

        // An entity met again, e.g. first as a nested relation reduced to its identifier, gets the fields of this row.
        if ($known !== null && $this->isUninitializedGhost($known)) {
            return $this->markComplete($this->completeGhost($known, $metadata, $data, $context, $depth), $metadata, $data, $context);
        }

        if ($known !== null) {
            // Only read for the #[AfterHydrate] methods, called once the entity gets its last fields.
            $wasComplete = $this->hookMethods($known::class, AfterHydrate::class) === [] || $this->areMappedFieldsInitialized($known, $metadata);
            $this->hydrateFields($known, $metadata, $data, $context, $depth);

            if ($this->holdsEveryField($metadata, $data)) {
                unset($this->partial[$known]);
            }

            if (!$wasComplete) {
                $this->callAfterHydrateMethods($known, $metadata);
            }

            return $this->markComplete($known, $metadata, $data, $context);
        }

        ++$context->trace->hydratedEntities;

        if ($identifier !== null && $uniqueField !== null && $context->lazyLoader !== null && ($lazy || $context->lazyLoading) && $this->isReducedToIdentifier($metadata, $data, $identifier->mappedFrom)) {
            $ghost = $this->lazyGhost($metadata, $identifier, $uniqueField, $context, $relation);
            $context->observer?->ghostCreated($metadata->class, $uniqueField, $relation);
            $property = $this->property($metadata->class, $identifier->property);
            $property->setRawValueWithoutLazyInitialization($ghost, $this->normalizeValue($data[$identifier->mappedFrom], $property, $identifier, $context));

            return $context->identityMap[$metadata->class][$uniqueField] = $ghost;
        }

        $entity = new ($metadata->class)();

        if ($uniqueField !== null) {
            $context->identityMap[$metadata->class][$uniqueField] = $entity;
        }

        $this->callBeforeHydrateMethods($entity, $data);
        $this->hydrateFields($entity, $metadata, $data, $context, $depth);
        $this->guardUnselectedProperties($entity, $metadata, $data, $context);
        $this->callAfterHydrateMethods($entity, $metadata);

        return $this->markComplete($entity, $metadata, $data, $context);
    }

    /**
     * Records an entity hydrated from a row holding every mapped field: one known only in part (a selection of its
     * fields) is not, whatever the default values of its properties.
     *
     * @param array<string|int, mixed> $data
     */
    private function markComplete(object $entity, GraphqlEntityMetadata $metadata, array $data, GraphqlExecutionContext $context): object
    {
        $context->unitOfWork?->hydrated($entity, $metadata, $data);

        foreach ($metadata->fields as $field) {
            if (!\array_key_exists($field->mappedFrom, $data)) {
                return $entity;
            }
        }

        $context->completeEntities[] = $entity;
        $this->keepInCache($metadata, $data, $context);

        return $entity;
    }

    /**
     * Keeps the row of an entity marked #[GraphqlOrm\Cache] in the second-level cache, its relations reduced to their
     * identifier: they are loaded on first access when it is read back. Not kept when a related entity has no
     * identifier.
     *
     * @param array<string|int, mixed> $data
     */
    private function keepInCache(GraphqlEntityMetadata $metadata, array $data, GraphqlExecutionContext $context): void
    {
        $identifier = $this->findUniqueField($metadata);
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

            $targetIdentifier = $this->findUniqueField($this->metadataFactory->getMetadata($field->relation))?->mappedFrom;

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

    /**
     * @param array<string|int, mixed> $data
     */
    private function hydrateFields(object $entity, GraphqlEntityMetadata $metadata, array $data, GraphqlExecutionContext $context, int $depth): void
    {
        foreach ($metadata->fields as $field) {
            if (!\array_key_exists($field->mappedFrom, $data)) {
                continue;
            }

            $value = $data[$field->mappedFrom];

            if ($field->embedded !== null) {
                $property = $this->property($metadata->class, $field->property);
                $value = \is_array($value)
                    ? EmbeddedRows::create($field->embedded, $value, fn (mixed $value, \ReflectionProperty $embeddedProperty, GraphqlFieldMetadata $embeddedField): mixed => $this->normalizeValue($value, $embeddedProperty, $embeddedField, $context))
                    : null;

                $this->isUninitializedGhost($entity)
                    ? $property->setRawValueWithoutLazyInitialization($entity, $value)
                    : $property->setValue($entity, $value);

                continue;
            }

            if ($field->relation !== null && \is_array($value)) {
                $relationMetadata = $this
                    ->metadataFactory
                    ->getMetadata($field->relation);

                if ($field->isCollection) {
                    ++$context->trace->hydratedCollections;

                    $value = array_map(
                        fn (mixed $row) => $this->hydrateEntity(
                            $relationMetadata,
                            \is_array($row) ? $row : throw InvalidGraphqlResponseException::expectedArray($row),
                            $context,
                            $depth + 1,
                            $field->fetch === Fetch::Lazy,
                            $metadata->class . '::$' . $field->property,
                        ),
                        $context->dialect->extractCollection($value)
                    );
                } elseif ($value) {
                    ++$context->trace->hydratedRelations;

                    $value = $this->hydrateEntity(
                        $relationMetadata,
                        $value,
                        $context,
                        $depth + 1,
                        $field->fetch === Fetch::Lazy,
                        $metadata->class . '::$' . $field->property,
                    );
                }
            }

            $property = $this->property($metadata->class, $field->property);

            $value = $field->isCollection && \is_array($value) && $this->type($property)[1]
                ? new ArrayCollection(array_values($value))
                : $this->normalizeValue($value, $property, $field, $context);

            if ($field->isCollection && is_countable($value)) {
                self::countCollection($context, $metadata->class . '::$' . $field->property, $value);
            }

            $this->isUninitializedGhost($entity)
                ? $property->setRawValueWithoutLazyInitialization($entity, $value)
                : $property->setValue($entity, $value);
        }
    }

    /**
     * The row, its embedded value objects nested under their key, and left out when it does not hold every field they
     * need: the property is then not selected, as a partial selection of them would build none.
     *
     * @param array<string|int, mixed> $data
     *
     * @return array<string|int, mixed>
     */
    private static function embeddedNested(GraphqlEntityMetadata $metadata, array $data): array
    {
        $data = EmbeddedRows::nest($metadata, $data);

        foreach (EmbeddedRows::embeddedFields($metadata) as $field) {
            if ($field->embedded === null) {
                continue;
            }

            $values = $data[$field->mappedFrom] ?? null;

            if (\is_array($values) && !EmbeddedRows::isComplete($field->embedded, $values)) {
                unset($data[$field->mappedFrom]);
            }
        }

        return $data;
    }

    /**
     * @param \Countable|array<mixed> $collection
     */
    private static function countCollection(GraphqlExecutionContext $context, string $relation, \Countable|array $collection): void
    {
        $counted = $context->trace->collections[$relation] ?? ['type' => get_debug_type($collection), 'collections' => 0, 'items' => 0];
        ++$counted['collections'];
        $counted['items'] += \count($collection);
        $context->trace->collections[$relation] = $counted;
    }

    /**
     * Remembers the mapped properties the row did not hold, and makes those of them left without a value throw a
     * PartialEntityException when read: the entity becomes a lazy ghost whose initializer throws, everything already
     * set (by the row, the constructor or a default value) being set again as is.
     *
     * @param array<string|int, mixed> $data
     */
    private function guardUnselectedProperties(object $entity, GraphqlEntityMetadata $metadata, array $data, GraphqlExecutionContext $context): void
    {
        $unselected = [];
        $missing = [];

        foreach ($metadata->fields as $field) {
            if (\array_key_exists($field->mappedFrom, $data)) {
                continue;
            }

            $unselected[] = $field->property;

            try {
                if (!$this->property($entity::class, $field->property)->isInitialized($entity)) {
                    $missing[] = $field->property;
                }
            } catch (\ReflectionException) {
            }
        }

        if ($unselected === []) {
            return;
        }

        $this->partial[$entity] = $unselected;
        $context->observer?->partialEntityHydrated($entity::class, $unselected);

        if ($missing === []) {
            return;
        }

        $reflection = $this->classReflection($entity::class);
        $observer = $context->observer;
        $values = [];

        foreach ($reflection->getProperties() as $property) {
            if (!$property->isStatic() && $property->isInitialized($entity)) {
                $values[] = [$property, $property->getValue($entity)];
            }
        }

        $reflection->resetAsLazyGhost(
            $entity,
            static function (object $ghost) use ($missing, $observer): never {
                $observer?->partialEntityRead($ghost::class, $missing);

                throw PartialEntityException::forProperties($ghost::class, $missing);
            },
            \ReflectionClass::SKIP_DESTRUCTOR,
        );

        foreach ($values as [$property, $value]) {
            $property->setRawValueWithoutLazyInitialization($entity, $value);
        }
    }

    /**
     * @param array<string|int, mixed> $data
     */
    private function holdsEveryField(GraphqlEntityMetadata $metadata, array $data): bool
    {
        foreach ($metadata->fields as $field) {
            if (!\array_key_exists($field->mappedFrom, $data)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A relation beyond max_depth only gets its identifier selected: nothing else to hydrate from this row.
     *
     * @param array<string|int, mixed> $data
     */
    private function isReducedToIdentifier(GraphqlEntityMetadata $metadata, array $data, string $identifierField): bool
    {
        return \count($metadata->fields) > 1 && array_diff(array_keys($data), [$identifierField, self::TYPENAME_FIELD]) === [];
    }

    /**
     * An entity of which only the identifier is set: its other properties are loaded on first access. The other ghosts
     * of its class met in the same response are loaded by the same query when the dialect allows it.
     *
     * @param string|null $relation the relation holding the ghost, e.g. Task::$assignee, told to the loader
     */
    private function lazyGhost(GraphqlEntityMetadata $metadata, GraphqlFieldMetadata $identifierField, int|string $identifier, GraphqlExecutionContext $context, ?string $relation): object
    {
        $class = $metadata->class;

        return $this->classReflection($class)->newLazyGhost(function (object $ghost) use ($class, $identifierField, $identifier, $context, $relation): void {
            $pending = [$identifier => $ghost];

            foreach ($context->knownEntitiesOf($class) as $key => $entity) {
                if ($entity !== $ghost && $this->isUninitializedGhost($entity) && !isset($this->partial[$entity])) {
                    $pending[$key] = $entity;
                }
            }

            $loader = $context->lazyLoader ?? throw EntityNotFoundException::forIdentifier($class, $identifier);
            $identifierProperty = $this->property($class, $identifierField->property);
            $loaded = [];

            foreach ($loader($class, array_keys($pending), $relation) as $entity) {
                $key = $identifierProperty->getValue($entity);

                if (\is_int($key) || \is_string($key)) {
                    $loaded[(string) $key] = $entity;
                }
            }

            $context->observer?->ghostsLoaded($class, array_values(array_filter(array_keys($pending), fn (int|string $key) => isset($loaded[(string) $key]))));
            $this->copyLoaded($loaded[(string) $identifier] ?? throw EntityNotFoundException::forIdentifier($class, $identifier), $ghost, $identifierField, raw: false);

            // The other ghosts are completed without their initializer; one missing from the result stays a ghost.
            foreach ($pending as $key => $other) {
                if ($other !== $ghost && isset($loaded[(string) $key])) {
                    $this->copyLoaded($loaded[(string) $key], $other, $identifierField, raw: true);
                    $this->classReflection($class)->markLazyObjectAsInitialized($other);
                }
            }
        });
    }

    /**
     * PHP gives the properties of a ghost being initialized their default values: every property but the identifier,
     * already set, is copied from the loaded entity.
     */
    private function copyLoaded(object $loaded, object $ghost, GraphqlFieldMetadata $identifierField, bool $raw): void
    {
        $this->instanceProperties[$ghost::class] ??= array_values(array_filter(
            $this->classReflection($ghost::class)->getProperties(),
            static fn (\ReflectionProperty $property): bool => !$property->isStatic(),
        ));

        foreach ($this->instanceProperties[$ghost::class] as $property) {
            if ($property->getName() === $identifierField->property || !$property->isInitialized($loaded)) {
                continue;
            }

            $raw
                ? $property->setRawValueWithoutLazyInitialization($ghost, $property->getValue($loaded))
                : $property->setValue($ghost, $property->getValue($loaded));
        }
    }

    private function isUninitializedGhost(object $entity): bool
    {
        return $this->classReflection($entity::class)->isUninitializedLazyObject($entity);
    }

    /**
     * A ghost met again: the fields of this row are set without loading it, and a row holding every mapped field makes
     * the query needless. PHP reports the properties of a ghost having a default value as initialized, so completeness
     * is read from the row, not from the object.
     *
     * @param array<string|int, mixed> $data
     */
    private function completeGhost(object $ghost, GraphqlEntityMetadata $metadata, array $data, GraphqlExecutionContext $context, int $depth): object
    {
        $this->hydrateFields($ghost, $metadata, $data, $context, $depth);

        foreach ($metadata->fields as $field) {
            if (!\array_key_exists($field->mappedFrom, $data)) {
                return $ghost;
            }
        }

        $this->classReflection($ghost::class)->markLazyObjectAsInitialized($ghost);
        unset($this->partial[$ghost]);
        $this->callAfterHydrateMethods($ghost, $metadata);

        return $ghost;
    }

    /**
     * @template C of object
     *
     * @param class-string<C> $class
     *
     * @return \ReflectionClass<C>
     */
    private function classReflection(string $class): \ReflectionClass
    {
        /** @var \ReflectionClass<C> $reflection */
        $reflection = $this->classes[$class] ??= new \ReflectionClass($class);

        return $reflection;
    }

    /**
     * @param array<string|int, mixed> $data
     */
    private function callBeforeHydrateMethods(object $entity, array $data): void
    {
        foreach ($this->hookMethods($entity::class, BeforeHydrate::class) as $method) {
            $method->invoke($entity, $data);
        }
    }

    private function callAfterHydrateMethods(object $entity, GraphqlEntityMetadata $metadata): void
    {
        $methods = $this->hookMethods($entity::class, AfterHydrate::class);

        if ($methods === [] || !$this->areMappedFieldsInitialized($entity, $metadata)) {
            return;
        }

        foreach ($methods as $method) {
            $method->invoke($entity);
        }
    }

    /**
     * @param class-string $class
     * @param class-string $attribute
     *
     * @return \ReflectionMethod[]
     */
    private function hookMethods(string $class, string $attribute): array
    {
        if (!isset($this->hookMethods[$class][$attribute])) {
            $this->hookMethods[$class][$attribute] = array_values(array_filter(
                (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC),
                fn (\ReflectionMethod $method) => $method->getAttributes($attribute) !== [],
            ));
        }

        return $this->hookMethods[$class][$attribute];
    }

    /**
     * @param class-string $class
     */
    private function property(string $class, string $name): \ReflectionProperty
    {
        return $this->properties[$class][$name] ??= new \ReflectionProperty($class, $name);
    }

    private function areMappedFieldsInitialized(object $entity, GraphqlEntityMetadata $metadata): bool
    {
        foreach ($metadata->fields as $field) {
            try {
                $property = $this->property($entity::class, $field->property);
            } catch (\ReflectionException) {
                continue;
            }

            if ($property->hasDefaultValue() || $property->getType() === null) {
                continue;
            }

            // A property left to a lazy initializer is not set: asking isInitialized() would run the initializer with
            // PHP 8.4, which throws for a partial entity.
            if ($property->isLazy($entity) || !$property->isInitialized($entity)) {
                return false;
            }
        }

        return true;
    }

    private function findUniqueField(GraphqlEntityMetadata $metadata): ?GraphqlFieldMetadata
    {
        if ($metadata->identifier !== null) {
            return $metadata->identifier;
        }

        foreach ($metadata->fields as $field) {
            if ($field->isIdentifier) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Whether the property is typed with a collection of doctrine/collections, which an ArrayCollection satisfies.
     */
    private static function acceptsArrayCollection(?\ReflectionType $type): bool
    {
        if (!class_exists(ArrayCollection::class) || $type === null) {
            return false;
        }

        foreach ($type instanceof \ReflectionUnionType ? $type->getTypes() : [$type] as $named) {
            if ($named instanceof \ReflectionNamedType && !$named->isBuiltin() && is_a(ArrayCollection::class, $named->getName(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A value converted by the type converter of its field, or to the type of its property.
     */
    private function normalizeValue(mixed $value, \ReflectionProperty $property, GraphqlFieldMetadata $field, GraphqlExecutionContext $context): mixed
    {
        $converter = $value !== null && $field->relation === null
            ? $context->typeConverters?->converterFor($field->converter, $field->valueClass)
            : null;

        return $converter !== null ? $converter->toPhp($value) : ValueCaster::cast($value, $this->type($property)[0]);
    }

    /**
     * @return array{\ReflectionType|null, bool} the type of the property, and whether an ArrayCollection fits it
     */
    private function type(\ReflectionProperty $property): array
    {
        if (!isset($this->types[$property->class][$property->name])) {
            $type = $property->getType();
            $this->types[$property->class][$property->name] = [$type, self::acceptsArrayCollection($type)];
        }

        return $this->types[$property->class][$property->name];
    }
}
