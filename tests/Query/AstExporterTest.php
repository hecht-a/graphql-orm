<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\Query\Ast\AstExporter;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;
use GraphqlOrm\Query\EnumLiteral;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstExporterTest extends TestCase
{
    #[Test]
    public function itExportsTheAstAsJsonWould(): void
    {
        $selection = new SelectionSetNode();
        $selection->add(new FieldNode('id'));
        $selection->add(new FieldNode('user', [], (function (): SelectionSetNode {
            $user = new SelectionSetNode();
            $user->add(new FieldNode('name'));

            return $user;
        })()));
        $query = new QueryNode();
        $query->fields[] = new FieldNode('tasks', [
            'filter' => ['and' => [['done' => ['eq' => false]], ['due_date' => ['lt' => new \DateTimeImmutable('2026-01-15T10:00:00+02:00')]]]],
            'orderBy' => new EnumLiteral('DUE_DATE_DESC'),
            'status' => FakeStatus::cases()[0],
            'ids' => [1, 2.5, null, 'x'],
            'first' => 10,
        ], $selection, true);

        self::assertSame(json_decode(json_encode($query, JSON_THROW_ON_ERROR), true), AstExporter::export($query));
    }
}
