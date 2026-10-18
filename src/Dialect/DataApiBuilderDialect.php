<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Query\Aggregate;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;
use GraphqlOrm\Query\EnumLiteral;
use GraphqlOrm\Query\Expr\FilterExpressionInterface;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\DABGraphqlWalker;
use GraphqlOrm\Query\Walker\GraphqlWalkerInterface;
use GraphqlOrm\Schema\CountableFieldResolver;

final class DataApiBuilderDialect implements GraphqlQueryDialect, CursorPaginationDialect, BatchLoadingDialect, CountingDialect, AggregatingDialect, MutationDialect
{
    /**
     * @param CountableFieldResolver|null $countableFields finds the field to count on when the identifier is no number
     */
    public function __construct(
        private readonly ?CountableFieldResolver $countableFields = null,
    ) {
    }

    public function extractCollection(array $data): array
    {
        $items = $data['items'] ?? [];

        return \is_array($items) ? $items : [];
    }

    public function createWalker(): GraphqlWalkerInterface
    {
        return new DABGraphqlWalker();
    }

    public function applyQueryOptions(array $arguments, QueryOptions $options): array
    {
        if ($options->limit !== null) {
            $arguments['first'] = $options->limit;
        }

        if ($options->orderBy !== null) {
            $arguments['orderBy'] = $options->orderBy;
        }

        if ($options->paginate) {
            if ($options->limit) {
                $arguments['first'] = $options->limit;
            }

            if ($options->cursor) {
                $arguments['after'] = $options->cursor;
            }
        }

        return $arguments;
    }

    public function applyFilter(?FilterExpressionInterface $filter): array
    {
        if (!$filter) {
            return [];
        }

        return ['filter' => $filter->toArray()];
    }

    public function applyCriteria(array $criteria): array
    {
        if ($criteria === []) {
            return [];
        }

        return ['filter' => array_map(
            // As with Doctrine, a list matches any of its values.
            fn (mixed $value) => match (true) {
                $value === null => ['isNull' => true],
                \is_array($value) && array_is_list($value) => ['in' => $value],
                default => ['eq' => $value],
            },
            $criteria,
        )];
    }

    public function hasNextPage(array $root): bool
    {
        return ($root['hasNextPage'] ?? false) === true;
    }

    public function endCursor(array $root): ?string
    {
        $cursor = $root['endCursor'] ?? null;

        return \is_string($cursor) ? $cursor : null;
    }

    /**
     * `tasks(filter) { groupBy { aggregations { count(field: id) } } }`: the count of an aggregation without grouping.
     * Data API Builder only counts the non-null values of a numeric field: the numeric identifier, or else a numeric
     * field never null, found in the schema.
     *
     * @throws LogicException when no field of the entity can be counted
     */
    public function countField(string $root, array $arguments, ?string $identifierField): FieldNode
    {
        $aggregations = new SelectionSetNode();
        $aggregations->add(new FieldNode('count', ['field' => new EnumLiteral($identifierField ?? $this->countableField($root))]));
        $groupBy = new SelectionSetNode();
        $groupBy->add(new FieldNode('aggregations', [], $aggregations));
        $selection = new SelectionSetNode();
        $selection->add(new FieldNode('groupBy', [], $groupBy));

        return new FieldNode($root, $arguments, $selection);
    }

    /**
     * @throws LogicException when the schema is unknown or the entity has no numeric field never null
     */
    private function countableField(string $root): string
    {
        $countable = $this->countableFields?->countableFields($root)
            ?? throw new LogicException(\sprintf('Data API Builder counts the values of a numeric field: count() needs a numeric identifier, or the introspection of the schema to find a numeric field of "%s" that is never null.', $root));

        return $countable[0]
            ?? throw new LogicException(\sprintf('Data API Builder counts the non-null values of a numeric field, and "%s" has no numeric field that is never null: count() cannot count it.', $root));
    }

    public function readCount(array $data, string $countRoot): int
    {
        $groups = \is_array($data[$countRoot] ?? null) ? ($data[$countRoot]['groupBy'] ?? null) : null;

        if (!\is_array($groups)) {
            throw InvalidGraphqlResponseException::expectedArray($groups);
        }

        $count = 0;

        foreach ($groups as $group) {
            $groupCount = \is_array($group) && \is_array($group['aggregations'] ?? null) ? ($group['aggregations']['count'] ?? null) : null;
            $count += \is_int($groupCount) ? $groupCount : throw InvalidGraphqlResponseException::expectedArray($group);
        }

        return $count;
    }

    /**
     * `tasks(filter) { groupBy { aggregations { sum(field: estimate) } } }`: Data API Builder only aggregates numbers.
     */
    public function aggregateField(string $root, array $arguments, Aggregate $aggregate, string $field): FieldNode
    {
        $aggregations = new SelectionSetNode();
        $aggregations->add(new FieldNode($aggregate->value, ['field' => new EnumLiteral($field)]));
        $groupBy = new SelectionSetNode();
        $groupBy->add(new FieldNode('aggregations', [], $aggregations));
        $selection = new SelectionSetNode();
        $selection->add(new FieldNode('groupBy', [], $groupBy));

        return new FieldNode($root, $arguments, $selection);
    }

    public function readAggregate(array $data, string $aggregateRoot, Aggregate $aggregate, string $field): int|float|string|null
    {
        $groups = \is_array($data[$aggregateRoot] ?? null) ? ($data[$aggregateRoot]['groupBy'] ?? null) : null;

        if (!\is_array($groups)) {
            throw InvalidGraphqlResponseException::expectedArray($groups);
        }

        // Without grouping, the aggregation has at most one group, and none when no entity matches.
        $group = $groups[0] ?? null;
        $value = \is_array($group) && \is_array($group['aggregations'] ?? null) ? ($group['aggregations'][$aggregate->value] ?? null) : null;

        return $value === null || \is_scalar($value) && !\is_bool($value) ? $value : throw InvalidGraphqlResponseException::expectedArray($group);
    }

    /**
     * `createTask(item: { ... }) { id }`.
     */
    public function createField(MutationTarget $target, array $values, SelectionSetNode $selection): FieldNode
    {
        return new FieldNode('create' . $target->type, ['item' => $values], $selection);
    }

    /**
     * `updateTask(id: 3, item: { ... }) { id }`: the primary key is an argument named after its field.
     */
    public function updateField(MutationTarget $target, int|string $identifier, array $values, SelectionSetNode $selection): FieldNode
    {
        return new FieldNode('update' . $target->type, [$target->identifierField => $identifier, 'item' => $values], $selection);
    }

    public function deleteField(MutationTarget $target, int|string $identifier, SelectionSetNode $selection): FieldNode
    {
        return new FieldNode('delete' . $target->type, [$target->identifierField => $identifier], $selection);
    }

    public function mutatedRow(MutationTarget $target, mixed $value): ?array
    {
        /** @var array<string, mixed>|null */
        return \is_array($value) ? $value : null;
    }
}
