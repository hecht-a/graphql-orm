<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;

/**
 * A dialect writing the entities: the mutations flush() sends to create, update and delete them, as fields of one
 * mutation document. Their values are keyed by GraphQL names, a relation by its join field (user_id), and converted to
 * what the API expects (value objects, enums and dates as scalars).
 */
interface MutationDialect
{
    /**
     * @param array<string, mixed> $values    the fields of the new entity, its identifier included when set
     * @param SelectionSetNode     $selection the fields to read from the entity created: its identifier
     */
    public function createField(MutationTarget $target, array $values, SelectionSetNode $selection): FieldNode;

    /**
     * @param array<string, mixed> $values the fields changed
     */
    public function updateField(MutationTarget $target, int|string $identifier, array $values, SelectionSetNode $selection): FieldNode;

    public function deleteField(MutationTarget $target, int|string $identifier, SelectionSetNode $selection): FieldNode;

    /**
     * The entity a mutation field answered with, e.g. the task of a PostGraphile payload: null when it wrote none.
     *
     * @param mixed $value the value of the mutation field in the response
     *
     * @return array<string, mixed>|null
     */
    public function mutatedRow(MutationTarget $target, mixed $value): ?array;
}
