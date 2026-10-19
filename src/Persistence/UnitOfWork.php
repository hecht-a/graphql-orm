<?php

declare(strict_types=1);

namespace GraphqlOrm\Persistence;

use GraphqlOrm\Converter\TypeConverterRegistry;
use GraphqlOrm\Dialect\MutationDialect;
use GraphqlOrm\Dialect\MutationTarget;
use GraphqlOrm\Exception\FlushException;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Query\Ast\QueryNode;

/**
 * The entities to write, as the Unit of Work of Doctrine: those persisted, those removed, and those whose fields changed
 * since a query read them. The values a query read are kept as the API knows them, by GraphQL name: flush() sends the
 * fields that differ, never one the query did not select.
 *
 * @internal
 */
final class UnitOfWork
{
    /**
     * @var \WeakMap<object, array<string, mixed>> the fields of the entities as the API knows them, by GraphQL name;
     *                                             a relation by the identifier of the related entity
     */
    private \WeakMap $originals;

    /** @var array<int, object> the entities persisted, by object id, in the order they were */
    private array $inserts = [];

    /** @var array<int, object> the entities removed, by object id */
    private array $deletes = [];

    private bool $flushing = false;

    /** @var array<string, array<string, \ReflectionProperty>> by class and property, as each row reads them */
    private array $properties = [];

    /**
     * @param \Closure(Lifecycle, object, int|string|null, list<string>): void $lifecycle told of each step of an entity
     *                                                                                    written, with its identifier when known
     *                                                                                    and the properties an update writes
     */
    public function __construct(
        private readonly GraphqlEntityMetadataFactory $metadataFactory,
        private readonly TypeConverterRegistry $typeConverters,
        private readonly \Closure $lifecycle,
    ) {
        $this->originals = new \WeakMap();
    }

    /**
     * Records the fields of an entity a row holds: the API has these values now.
     *
     * @param array<string|int, mixed> $row the row, the fields of its value objects nested
     */
    public function hydrated(object $entity, GraphqlEntityMetadata $metadata, array $row): void
    {
        $known = $this->originals[$entity] ?? [];

        foreach ($metadata->fields as $field) {
            if (!$field->isCollection && \array_key_exists($field->mappedFrom, $row)) {
                $known[$field->mappedFrom] = $this->currentValue($entity, $field);
            }
        }

        $this->originals[$entity] = $known;
    }

    /**
     * @throws InvalidArgumentException when the object is no entity
     */
    public function persist(object $entity): void
    {
        $this->assertEntity($entity);
        unset($this->deletes[spl_object_id($entity)]);

        if (!isset($this->originals[$entity]) && !isset($this->inserts[spl_object_id($entity)])) {
            $this->inserts[spl_object_id($entity)] = $entity;
            ($this->lifecycle)(Lifecycle::PrePersist, $entity, null, []);
        }
    }

    /**
     * @throws InvalidArgumentException when the object is no entity, or one the API does not know
     */
    public function remove(object $entity): void
    {
        $this->assertEntity($entity);

        if (isset($this->inserts[spl_object_id($entity)])) {
            unset($this->inserts[spl_object_id($entity)]);

            return;
        }

        if (!isset($this->originals[$entity])) {
            throw new InvalidArgumentException(\sprintf('This "%s" was not read from the API: only an entity read by a query can be removed.', $entity::class));
        }

        if (!isset($this->deletes[spl_object_id($entity)])) {
            $this->deletes[spl_object_id($entity)] = $entity;
            ($this->lifecycle)(Lifecycle::PreRemove, $entity, $this->identifier($entity, $this->metadataFactory->getMetadata($entity::class)), []);
        }
    }

    /**
     * Sends the mutations, a document per entity created whose identifier another needs.
     *
     * @param \Closure(QueryNode): array<string, mixed> $send sends a mutation document and gives its response
     *
     * @throws LogicException when an entity cannot be written, or a flush is running
     * @throws FlushException when a mutation fails
     */
    public function flush(MutationDialect $dialect, \Closure $send): void
    {
        if ($this->flushing) {
            throw new LogicException('flush() was called while the entities were being written: call it once the flush is done, e.g. from a PostFlushEvent listener.');
        }

        $this->flushing = true;

        try {
            $this->write($dialect, $send);
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * @param \Closure(QueryNode): array<string, mixed> $send
     *
     * @throws LogicException when an entity cannot be written
     * @throws FlushException when a mutation fails
     */
    private function write(MutationDialect $dialect, \Closure $send): void
    {
        $operations = $this->operations();
        $updated = false;

        foreach ($operations as $operation) {
            if ($operation->kind === Operation::UPDATE) {
                $properties = array_map(fn (string $name): string => $operation->metadata->field($name)->property ?? $name, $operation->changed);
                ($this->lifecycle)(Lifecycle::PreUpdate, $operation->entity, $operation->identifier, $properties);
                $updated = true;
            }
        }

        // What the callbacks and the listeners of the updates changed is written as well.
        if ($updated) {
            $operations = $this->operations();
        }

        $applied = 0;

        foreach (WritePlan::documents($operations) as $wave) {
            $document = new QueryNode();
            $document->operation = 'mutation';
            $names = [];

            foreach ($wave as $alias => $operation) {
                $field = $operation->field($dialect, $this->resolved($operation));
                $field->alias = $alias;
                $document->fields[] = $field;
                $names[$alias] = $operation->describe($field->name);
            }

            try {
                $response = $send($document);
            } catch (\Throwable $exception) {
                throw FlushException::failed($applied, \count($operations), array_map(fn (string $name): string => $name . ': ' . $exception->getMessage(), array_values($names)), $exception);
            }

            $data = \is_array($response['data'] ?? null) ? $response['data'] : [];
            $failures = [];

            foreach ($wave as $alias => $operation) {
                $row = $dialect->mutatedRow($operation->target, $data[$alias] ?? null);

                if ($row === null) {
                    $failures[] = $names[$alias] . ': ' . self::errorOf($response, $alias);

                    continue;
                }

                $this->applied($operation, $row);
                ++$applied;
            }

            if ($failures !== []) {
                throw FlushException::failed($applied, \count($operations), $failures);
            }
        }
    }

    /**
     * Whether flush() has an entity to write.
     *
     * @throws LogicException when an entity cannot be written
     */
    public function hasWork(): bool
    {
        return $this->operations() !== [];
    }

    /**
     * Forgets the entities to write and those read, as the identity map between two requests.
     */
    public function clear(): void
    {
        $this->originals = new \WeakMap();
        $this->inserts = [];
        $this->deletes = [];
    }

    /**
     * The entities created, updated and deleted, in this order.
     *
     * @return list<Operation>
     *
     * @throws LogicException when an entity cannot be written
     */
    private function operations(): array
    {
        $operations = [];

        foreach ($this->inserts as $entity) {
            $metadata = $this->metadataFactory->getMetadata($entity::class);
            $values = [];

            foreach ($metadata->fields as $field) {
                if (!$field->isCollection && $this->isInitialized($entity, $field) && !($field->isIdentifier && $this->currentValue($entity, $field) === null)) {
                    $values[$field->mappedFrom] = $this->currentValue($entity, $field);
                }
            }

            $operations[] = new Operation(Operation::CREATE, $entity, $metadata, $this->target($metadata), $this->writable($entity, $metadata, $values));
        }

        foreach ($this->originals as $entity => $original) {
            if (isset($this->deletes[spl_object_id($entity)]) || isset($this->inserts[spl_object_id($entity)])) {
                continue;
            }

            $metadata = $this->metadataFactory->getMetadata($entity::class);
            $changes = [];

            foreach ($original as $name => $value) {
                $field = $metadata->field($name);

                if ($field !== null && $this->isInitialized($entity, $field) && $this->currentValue($entity, $field) !== $value) {
                    $changes[$name] = $this->currentValue($entity, $field);
                }
            }

            if ($changes !== []) {
                $operations[] = new Operation(Operation::UPDATE, $entity, $metadata, $this->target($metadata), $this->writable($entity, $metadata, $changes), $this->identifier($entity, $metadata), array_keys($changes));
            }
        }

        foreach ($this->deletes as $entity) {
            $metadata = $this->metadataFactory->getMetadata($entity::class);
            $operations[] = new Operation(Operation::DELETE, $entity, $metadata, $this->target($metadata), [], $this->identifier($entity, $metadata));
        }

        return $operations;
    }

    /**
     * The values of an operation, the identifiers of the entities created before it known.
     *
     * @return array<string, mixed>
     */
    private function resolved(Operation $operation): array
    {
        $values = [];

        foreach ($operation->values as $name => $value) {
            $values[$name] = $value instanceof PendingIdentifier
                ? $this->identifier($value->entity, $this->metadataFactory->getMetadata($value->entity::class))
                : $value;
        }

        return $values;
    }

    /**
     * Records an operation the API applied: the identifier of an entity created, and the values the API now has.
     *
     * @param array<string, mixed> $row
     */
    private function applied(Operation $operation, array $row): void
    {
        $entity = $operation->entity;
        $identifierField = $operation->metadata->identifier;
        unset($this->inserts[spl_object_id($entity)], $this->deletes[spl_object_id($entity)]);

        if ($operation->kind === Operation::DELETE) {
            unset($this->originals[$entity]);
            ($this->lifecycle)(Lifecycle::PostRemove, $entity, $operation->identifier, []);

            return;
        }

        if ($operation->kind === Operation::CREATE && $identifierField !== null && $this->currentValue($entity, $identifierField) === null) {
            $identifier = $row[$identifierField->mappedFrom] ?? null;
            $this->property($entity, $identifierField->property)->setRawValue($entity, $identifier);
        }

        $known = $this->originals[$entity] ?? [];

        foreach ($operation->metadata->fields as $field) {
            if (!$field->isCollection && ($operation->kind === Operation::CREATE ? $this->isInitialized($entity, $field) : \in_array($field->mappedFrom, $operation->changed, true))) {
                $known[$field->mappedFrom] = $this->currentValue($entity, $field);
            }
        }

        $this->originals[$entity] = $known;

        ($this->lifecycle)($operation->kind === Operation::CREATE ? Lifecycle::PostPersist : Lifecycle::PostUpdate, $entity, $this->identifier($entity, $operation->metadata), []);
    }

    /**
     * The values of the fields to write as the mutations take them: a relation by its join field, the fields of a
     * value object of prefixed fields at the root.
     *
     * @param array<string, mixed> $values by GraphQL name
     *
     * @return array<string, mixed>
     *
     * @throws LogicException when a relation has no join field, or a related entity was not persisted
     */
    private function writable(object $entity, GraphqlEntityMetadata $metadata, array $values): array
    {
        $writable = [];

        foreach ($values as $name => $value) {
            $field = $metadata->field($name);

            if ($field?->relation !== null) {
                $writable[$field->joinField ?? throw new LogicException(\sprintf('"%s::$%s" has no join field to be written: name the field holding the identifier of the related entity with #[GraphqlOrm\Relation(joinField: \'%s_id\')].', $entity::class, $field->property, $field->mappedFrom))] = $this->pendingOrIdentifier($entity, $field, $value);

                continue;
            }

            if ($field?->embedded?->prefix !== null && (\is_array($value) || $value === null)) {
                foreach ($field->embedded->fields as $embeddedField) {
                    $writable[$field->embedded->graphqlName($embeddedField)] = \is_array($value) ? $value[$embeddedField->mappedFrom] ?? null : null;
                }

                continue;
            }

            $writable[$name] = $value;
        }

        return $writable;
    }

    /**
     * @throws LogicException when the related entity is new and was not persisted
     */
    private function pendingOrIdentifier(object $entity, GraphqlFieldMetadata $field, mixed $value): mixed
    {
        if (!$value instanceof PendingIdentifier) {
            return $value;
        }

        if (!isset($this->inserts[spl_object_id($value->entity)])) {
            throw new LogicException(\sprintf('"%s::$%s" holds a new "%s" that was not persisted: call persist() on it as well.', $entity::class, $field->property, $value->entity::class));
        }

        return $value;
    }

    /**
     * The value of a field as the API takes it: the identifier of a related entity (pending while it is new), the
     * fields of a value object, a converted value, an enum or a date as a scalar.
     */
    private function currentValue(object $entity, GraphqlFieldMetadata $field): mixed
    {
        $property = $this->property($entity, $field->property);
        // The value the entity holds, as the API knows it: the get hooks of the property are bypassed.
        $value = !$property->isLazy($entity) && $property->isInitialized($entity) ? $property->getRawValue($entity) : null;

        return $this->graphqlValue($field, $value);
    }

    private function graphqlValue(GraphqlFieldMetadata $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($field->relation !== null && \is_object($value)) {
            $metadata = $this->metadataFactory->getMetadata($value::class);
            $identifier = $metadata->identifier !== null ? $this->rawValue($value, $metadata->identifier->property) : null;

            return \is_int($identifier) || \is_string($identifier) ? $identifier : new PendingIdentifier($value);
        }

        if ($field->embedded !== null && \is_object($value)) {
            $fields = [];

            foreach ($field->embedded->fields as $embeddedField) {
                $fields[$embeddedField->mappedFrom] = $this->graphqlValue($embeddedField, $this->rawValue($value, $embeddedField->property));
            }

            return $fields;
        }

        $value = $this->typeConverters->toGraphql($field->converter, $field->valueClass, $value);

        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format(\DATE_ATOM),
            default => $value,
        };
    }

    /**
     * The value of a property, without loading a lazy entity: its identifier is set when it is created.
     */
    private function rawValue(object $object, string $property): mixed
    {
        $reflection = $this->property($object, $property);

        // isInitialized() would load a lazy object, as reading a property it has not set.
        if ($reflection->isLazy($object) || !$reflection->isInitialized($object)) {
            return null;
        }

        return $reflection->getRawValue($object);
    }

    private function isInitialized(object $entity, GraphqlFieldMetadata $field): bool
    {
        $property = $this->property($entity, $field->property);

        return !$property->isLazy($entity) && $property->isInitialized($entity);
    }

    private function property(object $object, string $name): \ReflectionProperty
    {
        return $this->properties[$object::class][$name] ??= new \ReflectionProperty($object, $name);
    }

    private function identifier(object $entity, GraphqlEntityMetadata $metadata): int|string
    {
        $identifier = $metadata->identifier !== null ? $this->rawValue($entity, $metadata->identifier->property) : null;

        return \is_int($identifier) || \is_string($identifier) ? $identifier : throw new LogicException(\sprintf('"%s" has no identifier to be written: mark its identifier field with #[GraphqlOrm\Identifier].', $entity::class));
    }

    /**
     * @throws LogicException when the entity has no identifier
     */
    private function target(GraphqlEntityMetadata $metadata): MutationTarget
    {
        $identifier = $metadata->identifier ?? throw new LogicException(\sprintf('"%s" has no identifier to be written: mark its identifier field with #[GraphqlOrm\Identifier].', $metadata->class));

        return new MutationTarget($metadata->name, $metadata->type, $identifier->mappedFrom);
    }

    /**
     * @throws InvalidArgumentException when the object is no entity
     */
    private function assertEntity(object $entity): void
    {
        if (!$this->metadataFactory->isEntityClass($entity::class)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not an entity: mark it with #[GraphqlOrm\Entity] to write it.', $entity::class));
        }
    }

    /**
     * The messages of the errors of a mutation field, by the alias their path starts with.
     *
     * @param array<string, mixed> $response
     */
    private static function errorOf(array $response, string $alias): string
    {
        $messages = [];

        foreach (\is_array($response['errors'] ?? null) ? $response['errors'] : [] as $error) {
            $path = \is_array($error) ? $error['path'] ?? null : null;

            if (\is_array($error) && (!\is_array($path) || ($path[0] ?? null) === $alias) && \is_string($error['message'] ?? null)) {
                $messages[] = $error['message'];
            }
        }

        return $messages !== [] ? implode(' | ', $messages) : 'the API answered without the entity.';
    }
}
