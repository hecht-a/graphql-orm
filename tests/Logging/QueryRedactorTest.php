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
        $redacted = $this->redactor->redactQuery($query);

        self::assertSame($expected, $redacted);
    }

    #[Test]
    public function itKeepsNamesEnumsKeywordsAndVariableReferences(): void
    {
        $query = 'query($v0: String) { tasks2(orderBy: { title: ASC }, done: true, owner: null, title: $v0) { id field2 } }';

        $redacted = $this->redactor->redactQuery($query);

        self::assertSame($query, $redacted);
    }

    #[Test]
    public function itMasksVariableValuesAndKeepsTheirKeys(): void
    {
        $variables = ['email' => 'user@example.com', 'filter' => ['age' => 42, 'tags' => ['a', 'b']], 'active' => null];

        $redacted = $this->redactor->redactValues($variables);

        self::assertSame(['email' => '***', 'filter' => ['age' => '***', 'tags' => ['***', '***']], 'active' => null], $redacted);
    }

    #[Test]
    public function itMasksArgumentsInTheAstAndKeepsTheStructure(): void
    {
        $ast = ['fields' => [[
            'name' => 'tasks',
            'arguments' => ['filter' => ['email' => ['eq' => 'user@example.com']]],
            'selectionSet' => ['fields' => [['name' => 'id', 'arguments' => [], 'selectionSet' => null, 'isCollection' => false]]],
            'isCollection' => false,
        ]]];

        $redacted = $this->redactor->redactAst($ast);

        $expected = $ast;
        $expected['fields'][0]['arguments'] = ['filter' => ['email' => ['eq' => '***']]];
        self::assertSame($expected, $redacted);
    }
}
