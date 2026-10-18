<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Client;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Client\PersistedQueries;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Automatic persisted queries, as Apollo: the hash of the query is sent alone, and the query once the server asks it.
 */
final class PersistedQueriesTest extends TestCase
{
    private const ENDPOINT = 'https://graphql.example.com/graphql';
    private const QUERY = 'query { tasks { id } }';
    private const DATA = '{"data":{"tasks":[{"id":1}]}}';

    #[Test]
    public function itSendsTheHashOfAQueryTheServerKnows(): void
    {
        $bodies = [];
        $client = $this->client($bodies, true, new MockResponse(self::DATA));
        $context = new GraphqlExecutionContext();

        $result = $client->query(self::QUERY, $context, ['id' => 1]);

        self::assertSame(['data' => ['tasks' => [['id' => 1]]]], $result);
        self::assertSame(
            [['variables' => ['id' => 1], 'extensions' => ['persistedQuery' => ['version' => 1, 'sha256Hash' => hash('sha256', self::QUERY)]]]],
            $bodies,
        );
        self::assertSame('hit', $context->trace->persistedQuery);
    }

    #[Test]
    public function itSendsTheQueryWhenTheServerDoesNotKnowItsHash(): void
    {
        $bodies = [];
        $client = $this->client(
            $bodies,
            true,
            new MockResponse('{"errors":[{"message":"PersistedQueryNotFound","extensions":{"code":"PERSISTED_QUERY_NOT_FOUND"}}]}'),
            new MockResponse(self::DATA),
        );
        $context = new GraphqlExecutionContext();

        $result = $client->query(self::QUERY, $context);

        self::assertSame(['data' => ['tasks' => [['id' => 1]]]], $result);
        self::assertSame(self::QUERY, $bodies[1]['query'] ?? null);
        self::assertSame(['persistedQuery' => ['version' => 1, 'sha256Hash' => hash('sha256', self::QUERY)]], $bodies[1]['extensions'] ?? null);
        self::assertSame('miss', $context->trace->persistedQuery);
        self::assertNull($context->trace->errors);
    }

    #[Test]
    public function itSendsTheQueryWhenTheServerAnswersAMissWithAnHttpError(): void
    {
        $bodies = [];
        $client = $this->client(
            $bodies,
            true,
            new MockResponse('{"errors":[{"message":"PersistedQueryNotFound"}]}', ['http_code' => 400]),
            new MockResponse(self::DATA),
        );

        self::assertSame(['data' => ['tasks' => [['id' => 1]]]], $client->query(self::QUERY, new GraphqlExecutionContext()));
        self::assertCount(2, $bodies);
    }

    #[Test]
    public function itSendsTheQueriesInFullOnceTheServerTellsItDoesNotSupportThem(): void
    {
        $bodies = [];
        $client = $this->client(
            $bodies,
            true,
            new MockResponse('{"errors":[{"message":"PersistedQueryNotSupported","extensions":{"code":"PERSISTED_QUERY_NOT_SUPPORTED"}}]}'),
            new MockResponse(self::DATA),
            new MockResponse(self::DATA),
        );

        $client->query(self::QUERY, new GraphqlExecutionContext());
        $context = new GraphqlExecutionContext();
        $client->query(self::QUERY, $context);

        self::assertSame([self::QUERY, self::QUERY], [$bodies[1]['query'] ?? null, $bodies[2]['query'] ?? null]);
        self::assertArrayNotHasKey('extensions', $bodies[1]);
        self::assertArrayNotHasKey('extensions', $bodies[2]);
        self::assertNull($context->trace->persistedQuery);
    }

    #[Test]
    public function itSendsTheQueryInFullWithoutPersistedQueries(): void
    {
        $bodies = [];
        $client = $this->client($bodies, false, new MockResponse(self::DATA));

        $client->query(self::QUERY, new GraphqlExecutionContext());

        self::assertSame(self::QUERY, $bodies[0]['query'] ?? null);
        self::assertArrayNotHasKey('extensions', $bodies[0]);
    }

    /**
     * @param list<array<string, mixed>> $bodies the bodies of the requests sent, decoded
     */
    private function client(array &$bodies, bool $persisted, MockResponse ...$responses): GraphqlClient
    {
        $responses = array_values($responses);
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies, &$responses): MockResponse {
            $body = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '', true);
            $bodies[] = \is_array($body) ? $body : [];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new GraphqlClient($http, self::ENDPOINT, persistedQueries: $persisted ? new PersistedQueries() : null);
    }
}
