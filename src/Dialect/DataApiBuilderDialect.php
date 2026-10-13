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

final class DataApiBuilderDialect implements GraphqlQueryDialect, CursorPaginationDialect, BatchLoadingDialect, CountingDialect, AggregatingDialect
{
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
            fn (mixed $value) => $value === null ? ['isNull' => true] : ['eq' => $value],
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
     *
     * @throws LogicException when the entity has no identifier, the field Data API Builder counts
     */
    public function countField(string $root, array $arguments, ?string $identifierField): FieldNode
    {
        if ($identifierField === null) {
            throw new LogicException('Data API Builder counts the values of a field: count() needs an entity identifier.');
        }

        $aggregations = new SelectionSetNode();
        $aggregations->add(new FieldNode('count', ['field' => new EnumLiteral($identifierField)]));
        $groupBy = new SelectionSetNode();
        $groupBy->add(new FieldNode('aggregations', [], $aggregations));
        $selection = new SelectionSetNode();
        $selection->add(new FieldNode('groupBy', [], $groupBy));

        return new FieldNode($root, $arguments, $selection);
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
}
