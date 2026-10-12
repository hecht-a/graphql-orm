<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Logging;

use GraphqlOrm\Logging\QueryRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QueryRedactorTest extends TestCase
{
    private QueryRedactor $redactor;

    protected function setUp(): void
    {
        $this->redactor = new QueryRedactor();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function queries(): iterable
    {
        yield 'string' => ['tasks(email: "user@example.com")', 'tasks(email: "***")'];
        yield 'escaped quote' => ['tasks(name: "Client \"A\" \\\\")', 'tasks(name: "***")'];
        yield 'nested input object' => ['tasks(filter: { email: { eq: "user@example.com" } })', 'tasks(filter: { email: { eq: "***" } })'];
        yield 'integer' => ['tasks(customerNumber: 4242)', 'tasks(customerNumber: ***)'];
        yield 'negative float with exponent' => ['tasks(amount: -12.5e3)', 'tasks(amount: ***)'];
        yield 'list' => ['tasks(ids: [1, 2])', 'tasks(ids: [***, ***])'];
        yield 'block string' => ['tasks(note: """line "one"
line two""")', 'tasks(note: "***")'];
    }

    #[Test]
    #[DataProvider('queries')]
    public function itMasksLiteralValues(string $query, string $expected): void
    {
        // Act
        $redacted = $this->redactor->redactQuery($query);

        // Assert
        self::assertSame($expected, $redacted);
    }

    #[Test]
    public function itKeepsNamesEnumsKeywordsAndVariableReferences(): void
    {
        // Arrange
        $query = 'query($v0: String) { tasks2(orderBy: { title: ASC }, done: true, owner: null, title: $v0) { id field2 } }';

        // Act
        $redacted = $this->redactor->redactQuery($query);

        // Assert
        self::assertSame($query, $redacted);
    }

    #[Test]
    public function itMasksVariableValuesAndKeepsTheirKeys(): void
    {
        // Arrange
        $variables = ['email' => 'user@example.com', 'filter' => ['age' => 42, 'tags' => ['a', 'b']], 'active' => null];

        // Act
        $redacted = $this->redactor->redactValues($variables);

        // Assert
        self::assertSame(['email' => '***', 'filter' => ['age' => '***', 'tags' => ['***', '***']], 'active' => null], $redacted);
    }

    #[Test]
    public function itMasksArgumentsInTheAstAndKeepsTheStructure(): void
    {
        // Arrange
        $ast = ['fields' => [[
            'name' => 'tasks',
            'arguments' => ['filter' => ['email' => ['eq' => 'user@example.com']]],
            'selectionSet' => ['fields' => [['name' => 'id', 'arguments' => [], 'selectionSet' => null, 'isCollection' => false]]],
            'isCollection' => false,
        ]]];

        // Act
        $redacted = $this->redactor->redactAst($ast);

        // Assert
        self::assertSame(['filter' => ['email' => ['eq' => '***']]], $redacted['fields'][0]['arguments']);
        self::assertSame('id', $redacted['fields'][0]['selectionSet']['fields'][0]['name']);
    }
}
