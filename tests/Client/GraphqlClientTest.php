<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Client;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Exception\GraphqlOrmException;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;

final class GraphqlClientTest extends TestCase
{
    private const ENDPOINT = 'https://graphql.example.com/graphql';
    private const QUERY = 'query { tasks { id } }';

    #[Test]
    public function itReturnsTheDecodedResponseOnSuccess(): void
    {
        $client = $this->createClient(new MockResponse('{"data":{"tasks":[{"id":1}]}}'));

        $result = $client->query(self::QUERY, new GraphqlExecutionContext());

        self::assertSame(['data' => ['tasks' => [['id' => 1]]]], $result);
    }

    #[Test]
    public function itReturnsPartialDataAndRecordsErrorsWhenTheResponseContainsBoth(): void
    {
        $client = $this->createClient(new MockResponse(
            '{"data":{"tasks":[{"id":1}]},"errors":[{"message":"Field \"secret\" is restricted"}]}'
        ));
        $context = new GraphqlExecutionContext();

        $result = $client->query(self::QUERY, $context);

        self::assertSame(['tasks' => [['id' => 1]]], $result['data']);
        self::assertSame([['message' => 'Field "secret" is restricted']], $context->trace->errors);
    }

    #[Test]
    public function itRetriesTheQueriesWithTheRetryableClientOfSymfonyWhenPostIsAllowed(): void
    {
        $responses = new MockHttpClient([
            new MockResponse('', ['http_code' => 504]),
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('{"data":{"tasks":[{"id":1}]}}'),
        ]);
        $retrying = new RetryableHttpClient($responses, new GenericRetryStrategy([500 => ['POST'], 504 => ['POST']], delayMs: 0), maxRetries: 2);

        $result = (new GraphqlClient($retrying, self::ENDPOINT))->query(self::QUERY, new GraphqlExecutionContext());

        self::assertSame(['data' => ['tasks' => [['id' => 1]]]], $result);
        self::assertSame(3, $responses->getRequestsCount());
    }

    #[Test]
    public function itIsNotRetriedOnAServerErrorByTheDefaultStrategyOfSymfony(): void
    {
        $responses = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('{"data":{"tasks":[]}}'),
        ]);
        $client = new GraphqlClient(new RetryableHttpClient($responses, new GenericRetryStrategy(delayMs: 0)), self::ENDPOINT);

        $exception = $this->catchQueryException($client);

        self::assertInstanceOf(GraphqlOrmException::class, $exception);
        self::assertSame(1, $responses->getRequestsCount());
    }

    #[Test]
    public function itThrowsABundleExceptionWhenTheResponseHasErrorsAndNoData(): void
    {
        $client = $this->createClient(new MockResponse(
            '{"data":null,"errors":[{"message":"Cannot query field \"foo\""}]}'
        ));

        $exception = $this->catchQueryException($client);

        self::assertInstanceOf(GraphqlOrmException::class, $exception);
        self::assertStringContainsString('Cannot query field "foo"', $exception->getMessage());
    }

    #[Test]
    public function itThrowsABundleExceptionWhenTheServerAnswersWithAnHttpError(): void
    {
        $client = $this->createClient(new MockResponse('<html>Bad Gateway</html>', ['http_code' => 502]));

        $exception = $this->catchQueryException($client);

        self::assertInstanceOf(GraphqlOrmException::class, $exception);
        self::assertStringContainsString('502', $exception->getMessage());
    }

    #[Test]
    public function itThrowsABundleExceptionWhenTheBodyIsNotValidJson(): void
    {
        $client = $this->createClient(new MockResponse('<html>Maintenance</html>'));

        $exception = $this->catchQueryException($client);

        self::assertInstanceOf(GraphqlOrmException::class, $exception);
    }

    #[Test]
    public function itSendsVariablesAsAJsonObjectEvenWhenThereIsNone(): void
    {
        $response = new MockResponse('{"data":{"tasks":[]}}');
        $client = $this->createClient($response);

        $client->query(self::QUERY, new GraphqlExecutionContext());

        $body = $response->getRequestOptions()['body'] ?? null;
        self::assertIsString($body);
        self::assertStringContainsString('"variables":{}', $body);
    }

    private function createClient(MockResponse $response): GraphqlClient
    {
        return new GraphqlClient(new MockHttpClient($response), self::ENDPOINT);
    }

    private function catchQueryException(GraphqlClient $client): ?\Throwable
    {
        try {
            $client->query(self::QUERY, new GraphqlExecutionContext());
        } catch (\Throwable $exception) {
            return $exception;
        }

        return null;
    }
}
