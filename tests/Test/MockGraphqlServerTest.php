<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Test;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Exception\GraphqlRequestException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Test\MockGraphqlServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MockGraphqlServerTest extends TestCase
{
    private const QUERY = 'query { tasks { id } }';

    #[Test]
    public function itAnswersWithTheQueuedDataInOrder(): void
    {
        $server = (new MockGraphqlServer())
            ->respond(['tasks' => [['id' => 1]]])
            ->respond(['tasks' => [['id' => 2]]]);
        $client = self::client($server);

        $first = $client->query(self::QUERY, new GraphqlExecutionContext());
        $second = $client->query(self::QUERY, new GraphqlExecutionContext());

        self::assertSame(['data' => ['tasks' => [['id' => 1]]]], $first);
        self::assertSame(['data' => ['tasks' => [['id' => 2]]]], $second);
    }

    #[Test]
    public function itRecordsTheQueriesAndVariablesSent(): void
    {
        $server = (new MockGraphqlServer())->respond(['tasks' => []])->respond(['tasks' => []]);
        $client = self::client($server);

        $client->query(self::QUERY, new GraphqlExecutionContext());
        $client->query('query ($id: Int) { tasks(id: $id) { id } }', new GraphqlExecutionContext(), ['id' => 3]);

        self::assertCount(2, $server->requests());
        self::assertSame(self::QUERY, $server->requests()[0]->query);
        self::assertSame([], $server->requests()[0]->variables);
        self::assertSame(['id' => 3], $server->requests()[1]->variables);
    }

    #[Test]
    public function itAnswersWithGraphqlErrors(): void
    {
        $client = self::client((new MockGraphqlServer())->respondWithErrors([['message' => 'Forbidden']]));

        $this->expectException(GraphqlRequestException::class);
        $this->expectExceptionMessage('Forbidden');

        $client->query(self::QUERY, new GraphqlExecutionContext());
    }

    #[Test]
    public function itAnswersWithPartialDataAlongsideErrors(): void
    {
        $client = self::client((new MockGraphqlServer())->respondWithErrors([['message' => 'Field "secret" is restricted']], ['tasks' => []]));
        $context = new GraphqlExecutionContext();

        $result = $client->query(self::QUERY, $context);

        self::assertSame(['tasks' => []], $result['data']);
        self::assertSame([['message' => 'Field "secret" is restricted']], $context->trace->errors);
    }

    #[Test]
    public function itAnswersWithAnHttpError(): void
    {
        $client = self::client((new MockGraphqlServer())->respondWithHttpStatus(503));

        $this->expectException(GraphqlRequestException::class);

        $client->query(self::QUERY, new GraphqlExecutionContext());
    }

    #[Test]
    public function itFailsWithTheQueryWhenNoResponseIsLeft(): void
    {
        $server = new MockGraphqlServer();
        $client = self::client($server);

        try {
            $client->query(self::QUERY, new GraphqlExecutionContext());
            self::fail('A query without response must fail.');
        } catch (LogicException $exception) {
            self::assertStringContainsString(self::QUERY, $exception->getMessage());
            self::assertCount(1, $server->requests());
        }
    }

    private static function client(MockGraphqlServer $server): GraphqlClient
    {
        return new GraphqlClient($server, 'https://graphql.example.com/graphql');
    }
}
