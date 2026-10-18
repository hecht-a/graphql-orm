<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\EnumLiteral;
use GraphqlOrm\Query\Expr\FilterExpressionInterface;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\GraphqlWalkerInterface;
use GraphqlOrm\Query\Walker\RelayGraphqlWalker;

/**
 * PostGraphile: Relay connections (`allTasks { edges { node } pageInfo }`), criteria sent as a `condition`, ordering
 * with the `orderBy` enum values of the fields (`orderBy: [DUE_DATE_DESC]`).
 *
 * The query builder filters need postgraphile-plugin-connection-filter, which core PostGraphile does not provide: they
 * are rejected. Without a list filter, lazy relations are loaded one query each.
 */
final class PostGraphileDialect implements GraphqlQueryDialect, CursorPaginationDialect, CountingDialect, MutationDialect
{
    private RelayDialect $relay;

    public function __construct()
    {
        $this->relay = new RelayDialect();
    }

    public function extractCollection(array $data): array
    {
        return $this->relay->extractCollection($data);
    }

    public function createWalker(): GraphqlWalkerInterface
    {
        return new RelayGraphqlWalker();
    }

    public function applyQueryOptions(array $arguments, QueryOptions $options): array
    {
        if ($options->orderBy !== null) {
            $arguments['orderBy'] = array_map(
                fn (string $field, Direction $direction) => new EnumLiteral($this->constantCase($field) . '_' . $direction->value),
                array_keys($options->orderBy),
                $options->orderBy,
            );
        }

        if ($options->limit !== null) {
            $arguments['first'] = $options->limit;
        }

        if ($options->paginate && $options->cursor !== null) {
            $arguments['after'] = $options->cursor;
        }

        return $arguments;
    }

    /**
     * @throws LogicException when a filter is given: core PostGraphile has none
     */
    public function applyFilter(?FilterExpressionInterface $filter): array
    {
        if ($filter === null) {
            return [];
        }

        throw new LogicException(\sprintf('The query builder filters need postgraphile-plugin-connection-filter, which "%s" does not support: use the find methods, sent as a condition.', self::class));
    }

    /**
     * @throws LogicException when a criterion is a list: a condition matches a single value
     */
    public function applyCriteria(array $criteria): array
    {
        foreach ($criteria as $field => $value) {
            if (\is_array($value) && array_is_list($value)) {
                throw new LogicException(\sprintf('PostGraphile conditions match a single value: "%s" cannot be matched against a list without postgraphile-plugin-connection-filter.', $field));
            }
        }

        return $criteria === [] ? [] : ['condition' => $criteria];
    }

    public function hasNextPage(array $root): bool
    {
        return $this->relay->hasNextPage($root);
    }

    public function endCursor(array $root): ?string
    {
        return $this->relay->endCursor($root);
    }

    /**
     * `dueDate` => `DUE_DATE`, as PostGraphile names the values of its orderBy enums.
     */
    private function constantCase(string $field): string
    {
        return strtoupper((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $field));
    }

    public function countField(string $root, array $arguments, ?string $identifierField): FieldNode
    {
        return $this->relay->countField($root, $arguments, $identifierField);
    }

    public function readCount(array $data, string $countRoot): int
    {
        return $this->relay->readCount($data, $countRoot);
    }

    /**
     * `createTask(input: { task: { ... } }) { task { id } }`: the entity is a field of the payload, named after its type.
     */
    public function createField(MutationTarget $target, array $values, SelectionSetNode $selection): FieldNode
    {
        return new FieldNode('create' . $target->type, ['input' => [lcfirst($target->type) => $values]], $this->payload($target, $selection));
    }

    /**
     * `updateTaskById(input: { id: 3, taskPatch: { ... } }) { task { id } }`: the mutation by the primary key.
     */
    public function updateField(MutationTarget $target, int|string $identifier, array $values, SelectionSetNode $selection): FieldNode
    {
        return new FieldNode(
            'update' . $target->type . $this->byIdentifier($target),
            ['input' => [$target->identifierField => $identifier, lcfirst($target->type) . 'Patch' => $values]],
            $this->payload($target, $selection),
        );
    }

    public function deleteField(MutationTarget $target, int|string $identifier, SelectionSetNode $selection): FieldNode
    {
        return new FieldNode(
            'delete' . $target->type . $this->byIdentifier($target),
            ['input' => [$target->identifierField => $identifier]],
            $this->payload($target, $selection),
        );
    }

    public function mutatedRow(MutationTarget $target, mixed $value): ?array
    {
        $row = \is_array($value) ? $value[lcfirst($target->type)] ?? null : null;

        /** @var array<string, mixed>|null */
        return \is_array($row) ? $row : null;
    }

    private function payload(MutationTarget $target, SelectionSetNode $selection): SelectionSetNode
    {
        $payload = new SelectionSetNode();
        $payload->add(new FieldNode(lcfirst($target->type), [], $selection));

        return $payload;
    }

    /**
     * `ById` for the field `id`, `ByCode` for `code`: PostGraphile names the mutations by primary key after its field.
     */
    private function byIdentifier(MutationTarget $target): string
    {
        return 'By' . str_replace(' ', '', ucwords(str_replace('_', ' ', $target->identifierField)));
    }
}
