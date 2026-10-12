<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Client;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GraphqlClientCallerTest extends TestCase
{
    #[Test]
    public function itRecordsTheCallerRelativeToTheProjectDirectory(): void
    {
        // Arrange
        $client = new GraphqlClient(
            new MockHttpClient(new MockResponse('{"data":{}}')),
            'https://graphql.example.com/graphql',
            projectDir: \dirname(__DIR__, 2),
        );
        $context = new GraphqlExecutionContext();

        // Act
        $client->query('query { tasks { id } }', $context);

        // Assert
        self::assertSame('tests/Client/GraphqlClientCallerTest.php', $context->trace->caller['file'] ?? null);
        self::assertSame(__CLASS__, $context->trace->caller['class'] ?? null);
    }
}
