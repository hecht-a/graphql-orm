<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query\Walker;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;
use GraphqlOrm\Query\GraphqlQueryCompiler;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\DefaultGraphqlWalker;
use PHPUnit\Framework\TestCase;

final class DefaultGraphqlWalkerTest extends TestCase
{
    public function testSimpleQuery(): void
    {
        $query = new QueryNode();

        $selection = new SelectionSetNode();
        $selection->add(new FieldNode('id'));
        $selection->add(new FieldNode('title'));

        $query->fields[] = new FieldNode(name: 'tasks', selectionSet: $selection);

        $walker = new DefaultGraphqlWalker();
        $graphql = $walker->walk($query, new QueryOptions());

        self::assertSame(
            <<<GQL
query {
  tasks {
    id
    title
  }
}
GQL,
            $graphql
        );
    }

    public function testNestedRelation(): void
    {
        $query = new QueryNode();

        $userSelection = new SelectionSetNode();
        $userSelection->add(new FieldNode('id'));
        $userSelection->add(new FieldNode('name'));

        $taskSelection = new SelectionSetNode();
        $taskSelection->add(new FieldNode('id'));
        $taskSelection->add(new FieldNode('user', selectionSet: $userSelection));

        $query->fields[] = new FieldNode('tasks', selectionSet: $taskSelection);

        $walker = new DefaultGraphqlWalker();
        $graphql = $walker->walk($query, new QueryOptions());

        self::assertSame(
            <<<GQL
query {
  tasks {
    id
    user {
      id
      name
    }
  }
}
GQL,
            $graphql
        );
    }

    public function testArguments(): void
    {
        $query = new QueryNode();
        $selection = new SelectionSetNode();
        $selection->add(new FieldNode('id'));

        $query->fields[] = new FieldNode(
            name: 'task',
            arguments: [
                'id' => 1,
                'active' => true,
            ],
            selectionSet: $selection
        );

        $walker = new DefaultGraphqlWalker();
        $graphql = $walker->walk($query, new QueryOptions());

        self::assertStringContainsString('task(id: 1, active: true)', $graphql);
    }

    public function testFormatsValues(): void
    {
        $walker = new DefaultGraphqlWalker();

        $ref = new \ReflectionClass($walker);
        $method = $ref->getMethod('formatValue');
        $method->setAccessible(true);

        self::assertSame('"hello"', $method->invoke($walker, 'hello'));
        self::assertSame('true', $method->invoke($walker, true));
        self::assertSame('null', $method->invoke($walker, null));
        self::assertSame('[1, 2]', $method->invoke($walker, [1, 2]));
    }

    public function testThrowsOnUnsupportedType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $walker = new DefaultGraphqlWalker();

        $ref = new \ReflectionClass($walker);
        $method = $ref->getMethod('formatValue');
        $method->setAccessible(true);

        $method->invoke($walker, new \stdClass());
    }

    public function testCompilerUsesWalker(): void
    {
        $query = new QueryNode();

        $selection = new SelectionSetNode();
        $selection->add(new FieldNode('id'));

        $query->fields[] = new FieldNode('tasks', selectionSet: $selection);

        $compiler = new GraphqlQueryCompiler(new DefaultGraphqlWalker());

        $graphql = $compiler->compile($query, new QueryOptions());

        self::assertStringContainsString('tasks', $graphql);
    }
}
