<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query\Walker;

use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\DABGraphqlWalker;
use GraphqlOrm\Query\Walker\DefaultGraphqlWalker;
use GraphqlOrm\Query\Walker\VariableAwareWalkerInterface;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WalkerVariablesTest extends TestCase
{
    private const FILTER = ['email' => ['eq' => 'user@example.com']];

    /**
     * @return iterable<string, array{VariableAwareWalkerInterface}>
     */
    public static function walkers(): iterable
    {
        yield 'default' => [new DefaultGraphqlWalker()];
        yield 'data api builder' => [new DABGraphqlWalker()];
    }

    #[Test]
    #[DataProvider('walkers')]
    public function itDeclaresTypedArgumentsAsVariables(VariableAwareWalkerInterface $walker): void
    {
        // Arrange
        $query = $this->queryWithArguments(['filter' => self::FILTER, 'first' => 10]);

        // Act
        $compiled = $walker->walkWithVariables($query, new QueryOptions(), ['filter' => 'TaskFilterInput', 'first' => 'Int']);

        // Assert
        self::assertStringStartsWith("query(\$filter: TaskFilterInput, \$first: Int) {\n  things(filter: \$filter, first: \$first) {", $compiled->graphql);
        self::assertStringNotContainsString('user@example.com', $compiled->graphql);
        self::assertSame(['filter' => self::FILTER, 'first' => 10], $compiled->variables);
        self::assertSame([], $compiled->untypedArguments);
    }

    #[Test]
    #[DataProvider('walkers')]
    public function itSendsVariableValuesAsJsonCompatibleValues(VariableAwareWalkerInterface $walker): void
    {
        // Arrange
        $query = $this->queryWithArguments([
            'orderBy' => ['title' => Direction::DESC],
            'since' => new \DateTimeImmutable('2026-01-15T10:00:00+00:00'),
            'status' => FakeStatus::Open,
        ]);

        // Act
        $compiled = $walker->walkWithVariables($query, new QueryOptions(), [
            'orderBy' => 'TaskOrderByInput',
            'since' => 'DateTime',
            'status' => 'Status',
        ]);

        // Assert
        self::assertSame(
            ['orderBy' => ['title' => 'DESC'], 'since' => '2026-01-15T10:00:00+00:00', 'status' => 'OPEN'],
            $compiled->variables,
        );
    }

    #[Test]
    #[DataProvider('walkers')]
    public function itFallsBackToInlineValuesWhenAnArgumentTypeIsUnknown(VariableAwareWalkerInterface $walker): void
    {
        // Arrange
        $query = $this->queryWithArguments(['filter' => self::FILTER, 'first' => 10]);

        // Act
        $compiled = $walker->walkWithVariables($query, new QueryOptions(), ['filter' => 'TaskFilterInput']);

        // Assert
        self::assertSame($walker->walk($query, new QueryOptions()), $compiled->graphql);
        self::assertSame([], $compiled->variables);
        self::assertSame(['first'], $compiled->untypedArguments);
    }

    #[Test]
    #[DataProvider('walkers')]
    public function itDeclaresNoVariableForARootFieldWithoutArguments(VariableAwareWalkerInterface $walker): void
    {
        // Arrange
        $query = $this->queryWithArguments([]);

        // Act
        $compiled = $walker->walkWithVariables($query, new QueryOptions(), ['first' => 'Int']);

        // Assert
        self::assertStringStartsWith("query {\n", $compiled->graphql);
        self::assertSame([], $compiled->variables);
    }

    #[Test]
    public function itTurnsDataApiBuilderPaginationArgumentsIntoVariables(): void
    {
        // Arrange
        $options = new QueryOptions();
        $options->paginate = true;
        $options->limit = 5;
        $options->cursor = 'c1';

        // Act
        $compiled = (new DABGraphqlWalker())->walkWithVariables(
            $this->queryWithArguments([]),
            $options,
            ['first' => 'Int', 'after' => 'String'],
        );

        // Assert
        self::assertStringContainsString('things(first: $first, after: $after)', $compiled->graphql);
        self::assertSame(['first' => 5, 'after' => 'c1'], $compiled->variables);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function queryWithArguments(array $arguments): QueryNode
    {
        $selection = new SelectionSetNode();
        $selection->add(new FieldNode('id'));

        $query = new QueryNode();
        $query->fields[] = new FieldNode('things', $arguments, $selection);

        return $query;
    }
}
