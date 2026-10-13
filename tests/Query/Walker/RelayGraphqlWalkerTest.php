<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query\Walker;

use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\RelayGraphqlWalker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RelayGraphqlWalkerTest extends TestCase
{
    #[Test]
    public function itSelectsTheNodesOfTheRootConnectionAndOfNestedCollections(): void
    {
        $graphql = (new RelayGraphqlWalker())->walk($this->query(), new QueryOptions());

        self::assertSame(<<<'GQL'
            query {
              tasks(first: 10) {
                edges {
                  node {
                    id
                    tags {
                      edges {
                        node {
                          name
                        }
                      }
                    }
                  }
                }
              }
            }
            GQL, $graphql);
    }

    #[Test]
    public function itSelectsThePageInfoWhenPaginating(): void
    {
        $options = new QueryOptions();
        $options->paginate = true;

        $graphql = (new RelayGraphqlWalker())->walk($this->query(), $options);

        self::assertStringContainsString("  }\n    pageInfo {\n      hasNextPage\n      endCursor\n    }\n  }\n}", $graphql);
    }

    private function query(): QueryNode
    {
        $tagFields = new SelectionSetNode();
        $tagFields->add(new FieldNode('name'));

        $taskFields = new SelectionSetNode();
        $taskFields->add(new FieldNode('id'));
        $taskFields->add(new FieldNode('tags', [], $tagFields, isCollection: true));

        $query = new QueryNode();
        $query->fields[] = new FieldNode('tasks', ['first' => 10], $taskFields);

        return $query;
    }
}
