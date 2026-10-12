<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query\Walker;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\Walker\DABGraphqlWalker;
use GraphqlOrm\Query\Walker\DefaultGraphqlWalker;
use GraphqlOrm\Query\Walker\GraphqlWalkerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WalkerArgumentFormattingTest extends TestCase
{
    /**
     * @return iterable<string, array{GraphqlWalkerInterface}>
     */
    public static function walkers(): iterable
    {
        yield 'default' => [new DefaultGraphqlWalker()];
        yield 'data api builder' => [new DABGraphqlWalker()];
    }

    #[Test]
    #[DataProvider('walkers')]
    public function itEscapesDoubleQuotesInStringValues(GraphqlWalkerInterface $walker): void
    {
        $query = $this->queryWithArguments(['name' => 'O"Brien']);

        $graphql = $walker->walk($query, new QueryOptions());

        self::assertStringContainsString('things(name: "O\"Brien")', $graphql);
    }

    #[Test]
    #[DataProvider('walkers')]
    public function itEscapesBackslashesAndLineBreaksInStringValues(GraphqlWalkerInterface $walker): void
    {
        $query = $this->queryWithArguments(['name' => "a\\b\nc"]);

        $graphql = $walker->walk($query, new QueryOptions());

        self::assertStringContainsString('things(name: "a\\\\b\nc")', $graphql);
    }

    #[Test]
    #[DataProvider('walkers')]
    public function itKeepsACraftedValueInsideItsStringLiteral(GraphqlWalkerInterface $walker): void
    {
        $query = $this->queryWithArguments(['name' => 'x") { id } secrets(where: "']);

        $graphql = $walker->walk($query, new QueryOptions());

        self::assertStringContainsString('things(name: "x\") { id } secrets(where: \"")', $graphql);
    }

    #[Test]
    #[DataProvider('walkers')]
    public function itRejectsAnArgumentNameThatIsNotAValidGraphqlName(GraphqlWalkerInterface $walker): void
    {
        $query = $this->queryWithArguments(['name) { id } secrets(x' => 'value']);

        $this->expectException(InvalidArgumentException::class);
        $walker->walk($query, new QueryOptions());
    }

    #[Test]
    #[DataProvider('walkers')]
    public function itRejectsAnInputObjectKeyThatIsNotAValidGraphqlName(GraphqlWalkerInterface $walker): void
    {
        $query = $this->queryWithArguments(['filter' => ['name: "a" }, secret: { eq' => 'x']]);

        $this->expectException(InvalidArgumentException::class);
        $walker->walk($query, new QueryOptions());
    }

    #[Test]
    #[DataProvider('walkers')]
    public function itPrintsAnAssociativeArrayAsAnInputObject(GraphqlWalkerInterface $walker): void
    {
        $query = $this->queryWithArguments(['status' => ['eq' => 'active']]);

        $graphql = $walker->walk($query, new QueryOptions());

        self::assertStringContainsString('things(status: { eq: "active" })', $graphql);
    }

    #[Test]
    #[DataProvider('walkers')]
    public function itPrintsAListAsAGraphqlList(GraphqlWalkerInterface $walker): void
    {
        $query = $this->queryWithArguments(['ids' => [1, 2]]);

        $graphql = $walker->walk($query, new QueryOptions());

        self::assertStringContainsString('things(ids: [1, 2])', $graphql);
    }

    #[Test]
    #[DataProvider('walkers')]
    public function itRejectsANonFiniteFloat(GraphqlWalkerInterface $walker): void
    {
        $query = $this->queryWithArguments(['ratio' => NAN]);

        $this->expectException(InvalidArgumentException::class);
        $walker->walk($query, new QueryOptions());
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
