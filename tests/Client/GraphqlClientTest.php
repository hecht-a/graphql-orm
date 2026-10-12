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

final class GraphqlClientTest extends TestCase
{
    private const ENDPOINT = 'https://graphql.example.com/graphql';
    private const QUERY = 'query { tasks { id } }';

    #[Test]
    public function itReturnsTheDecodedResponseOnSuccess(): void
    {
        // Arrange
        $client = $this->createClient(new MockResponse('{"data":{"tasks":[{"id":1}]}}'));

        // Act
        $result = $client->query(self::QUERY, new GraphqlExecutionContext());

        // Assert
        self::assertSame(['data' => ['tasks' => [['id' => 1]]]], $result);
    }

    #[Test]
    public function itReturnsPartialDataAndRecordsErrorsWhenTheResponseContainsBoth(): void
    {
        // Arrange
        $client = $this->createClient(new MockResponse(
            '{"data":{"tasks":[{"id":1}]},"errors":[{"message":"Field \"secret\" is restricted"}]}'
        ));
        $context = new GraphqlExecutionContext();

        // Act
        $result = $client->query(self::QUERY, $context);

        // Assert
        self::assertSame([['id' => 1]], $result['data']['tasks']);
        self::assertSame([['message' => 'Field "secret" is restricted']], $context->trace->errors);
    }

    #[Test]
    public function itThrowsABundleExceptionWhenTheResponseHasErrorsAndNoData(): void
    {
        // Arrange
        $client = $this->createClient(new MockResponse(
            '{"data":null,"errors":[{"message":"Cannot query field \"foo\""}]}'
        ));

        // Act
        $exception = $this->catchQueryException($client);

        // Assert
        self::assertInstanceOf(GraphqlOrmException::class, $exception);
        self::assertStringContainsString('Cannot query field "foo"', $exception->getMessage());
    }

    #[Test]
    public function itThrowsABundleExceptionWhenTheServerAnswersWithAnHttpError(): void
    {
        // Arrange
        $client = $this->createClient(new MockResponse('<html>Bad Gateway</html>', ['http_code' => 502]));

        // Act
        $exception = $this->catchQueryException($client);

        // Assert
        self::assertInstanceOf(GraphqlOrmException::class, $exception);
        self::assertStringContainsString('502', $exception->getMessage());
    }

    #[Test]
    public function itThrowsABundleExceptionWhenTheBodyIsNotValidJson(): void
    {
        // Arrange
        $client = $this->createClient(new MockResponse('<html>Maintenance</html>'));

        // Act
        $exception = $this->catchQueryException($client);

        // Assert
        self::assertInstanceOf(GraphqlOrmException::class, $exception);
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
