<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query\Printer;

use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;
use GraphqlOrm\Query\Printer\GraphqlPrinter;
use PHPUnit\Framework\TestCase;

final class GraphqlPrinterTest extends TestCase
{
    public function testIndentation(): void
    {
        $printer = new GraphqlPrinter();
        $printer->line('query {');
        $printer->indent();
        $printer->line('tasks');
        $printer->outdent();
        $printer->line('}');

        self::assertSame(
            <<<TXT
query {
  tasks
}
TXT,
            $printer->get()
        );
    }

    public function testAddField(): void
    {
        $set = new SelectionSetNode();
        $set->add(new FieldNode('id'));
        $set->add(new FieldNode('title'));

        self::assertCount(2, $set->fields);
        self::assertSame('title', $set->fields[1]->name);
    }

    public function testFieldNodeStoresValues(): void
    {
        $selection = new SelectionSetNode();

        $field = new FieldNode('task', ['id' => 1], $selection);

        self::assertSame('task', $field->name);
        self::assertSame(1, $field->arguments['id']);
        self::assertSame($selection, $field->selectionSet);
    }
}
