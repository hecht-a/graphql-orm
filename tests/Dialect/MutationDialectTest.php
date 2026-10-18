<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Dialect;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Dialect\HasuraDialect;
use GraphqlOrm\Dialect\MutationDialect;
use GraphqlOrm\Dialect\MutationTarget;
use GraphqlOrm\Dialect\PostGraphileDialect;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;
use GraphqlOrm\Query\QueryOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The mutations flush() sends to create, update and delete an entity, as each API names them.
 */
final class MutationDialectTest extends TestCase
{
    /**
     * @return iterable<string, array{MutationDialect&GraphqlQueryDialect, string, string, string}>
     */
    public static function dialects(): iterable
    {
        yield 'Data API Builder' => [
            new DataApiBuilderDialect(),
            'createTask(item: { title: "Task A", user_id: 7 }) { id }',
            'updateTask(id: 3, item: { title: "Task B", due_date: null }) { id }',
            'deleteTask(id: 3) { id }',
        ];
        yield 'PostGraphile' => [
            new PostGraphileDialect(),
            'createTask(input: { task: { title: "Task A", user_id: 7 } }) { task { id } }',
            'updateTaskById(input: { id: 3, taskPatch: { title: "Task B", due_date: null } }) { task { id } }',
            'deleteTaskById(input: { id: 3 }) { task { id } }',
        ];
        yield 'Hasura' => [
            new HasuraDialect(),
            'insert_tasks_one(object: { title: "Task A", user_id: 7 }) { id }',
            'update_tasks_by_pk(pk_columns: { id: 3 }, _set: { title: "Task B", due_date: null }) { id }',
            'delete_tasks_by_pk(id: 3) { id }',
        ];
    }

    #[Test]
    #[DataProvider('dialects')]
    public function itNamesTheMutationsOfAnEntity(MutationDialect&GraphqlQueryDialect $dialect, string $create, string $update, string $delete): void
    {
        $target = new MutationTarget('tasks', 'Task', 'id');

        self::assertSame(
            'mutation { m0: ' . $create . ' m1: ' . $update . ' m2: ' . $delete . ' }',
            self::print($dialect, [
                $dialect->createField($target, ['title' => 'Task A', 'user_id' => 7], self::identifier()),
                $dialect->updateField($target, 3, ['title' => 'Task B', 'due_date' => null], self::identifier()),
                $dialect->deleteField($target, 3, self::identifier()),
            ]),
        );
    }

    #[Test]
    public function itNamesTheMutationsOfPostGraphileByTheIdentifier(): void
    {
        $dialect = new PostGraphileDialect();
        $target = new MutationTarget('labels', 'Label', 'code');

        self::assertSame(
            'mutation { m0: deleteLabelByCode(input: { code: "bug" }) { label { code } } }',
            self::print($dialect, [$dialect->deleteField($target, 'bug', self::identifier('code'))]),
        );
    }

    #[Test]
    public function itReadsTheEntityOfAPostGraphilePayload(): void
    {
        $target = new MutationTarget('tasks', 'Task', 'id');

        self::assertSame(['id' => 3], (new PostGraphileDialect())->mutatedRow($target, ['task' => ['id' => 3]]));
        self::assertNull((new PostGraphileDialect())->mutatedRow($target, null));
        self::assertSame(['id' => 3], (new HasuraDialect())->mutatedRow($target, ['id' => 3]));
        self::assertNull((new HasuraDialect())->mutatedRow($target, null));
        self::assertSame(['id' => 3], (new DataApiBuilderDialect())->mutatedRow($target, ['id' => 3]));
    }

    private static function identifier(string $field = 'id'): SelectionSetNode
    {
        $selection = new SelectionSetNode();
        $selection->add(new FieldNode($field));

        return $selection;
    }

    /**
     * @param list<FieldNode> $fields
     */
    private static function print(GraphqlQueryDialect $dialect, array $fields): string
    {
        $mutation = new QueryNode();
        $mutation->operation = 'mutation';

        foreach ($fields as $index => $field) {
            $field->alias = 'm' . $index;
            $mutation->fields[] = $field;
        }

        return (string) preg_replace('/\s+/', ' ', $dialect->createWalker()->walk($mutation, new QueryOptions()));
    }
}
