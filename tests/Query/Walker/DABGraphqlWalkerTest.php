<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query\Walker;

use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;
use GraphqlOrm\Query\GraphqlQueryCompiler;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\DABGraphqlWalker;
use PHPUnit\Framework\TestCase;

final class DABGraphqlWalkerTest extends TestCase
{
    public function testWrapsItems(): void
    {
        $query = new QueryNode();

        $selection = new SelectionSetNode();
        $selection->add(new FieldNode('id'));

        $query->fields[] = new FieldNode('tasks', selectionSet: $selection);

        $walker = new DABGraphqlWalker();
        $graphql = $walker->walk($query, new QueryOptions());

        self::assertSame(
            <<<GQL
query {
  tasks {
    items {
      id
    }
  }
}
GQL,
            $graphql
        );
    }

    public function testCompilerUsesWalker(): void
    {
        $query = new QueryNode();

        $selection = new SelectionSetNode();
        $selection->add(new FieldNode('id'));

        $query->fields[] = new FieldNode(
            'tasks',
            selectionSet: $selection
        );

        $compiler = new GraphqlQueryCompiler(new DABGraphqlWalker());

        $graphql = $compiler->compile($query, new QueryOptions());

        self::assertStringContainsString('tasks', $graphql);
    }
}
