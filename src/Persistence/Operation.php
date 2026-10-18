<?php

declare(strict_types=1);

namespace GraphqlOrm\Persistence;

use GraphqlOrm\Dialect\MutationDialect;
use GraphqlOrm\Dialect\MutationTarget;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;

/**
 * An entity to create, update or delete, with the values to send.
 *
 * @internal
 */
final readonly class Operation
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const DELETE = 'delete';

    /**
     * @param self::CREATE|self::UPDATE|self::DELETE $kind
     * @param array<string, mixed>                   $values  the fields to send, by GraphQL name; a related entity
     *                                                        created by the same flush as a PendingIdentifier
     * @param list<string>                           $changed the fields of an update, by GraphQL name
     */
    public function __construct(
        public string $kind,
        public object $entity,
        public GraphqlEntityMetadata $metadata,
        public MutationTarget $target,
        public array $values = [],
        public int|string|null $identifier = null,
        public array $changed = [],
    ) {
    }

    /**
     * @param array<string, mixed> $values the fields to send, the identifiers of the entities created before known
     */
    public function field(MutationDialect $dialect, array $values): FieldNode
    {
        $selection = new SelectionSetNode();
        $selection->add(new FieldNode($this->target->identifierField));

        return match ($this->kind) {
            self::CREATE => $dialect->createField($this->target, $values, $selection),
            self::UPDATE => $dialect->updateField($this->target, $this->identifier ?? '', $values, $selection),
            self::DELETE => $dialect->deleteField($this->target, $this->identifier ?? '', $selection),
        };
    }

    /**
     * E.g. `updateNote 3 (App\Entity\Note)`, as the failures of a flush name the operations.
     */
    public function describe(string $mutation): string
    {
        return $mutation . ($this->identifier !== null ? ' ' . $this->identifier : '') . ' (' . $this->entity::class . ')';
    }
}
