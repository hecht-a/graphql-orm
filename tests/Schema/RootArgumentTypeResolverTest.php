<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Schema;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Fixtures\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RootArgumentTypeResolverTest extends TestCase
{
    private const TTL = 3600;

    #[Test]
    public function itPrintsArgumentTypesWithTheirWrappers(): void
    {
        // Arrange
        $resolver = $this->createResolver(new FakeGraphqlClient(self::introspectionResponse()));

        // Act
        $types = $resolver->argumentTypes('tasks');

        // Assert
        self::assertSame(['filter' => 'TaskFilterInput', 'ids' => '[ID!]!', 'first' => 'Int'], $types);
    }

    #[Test]
    public function itReturnsNullForAnUnknownRootField(): void
    {
        // Arrange
        $resolver = $this->createResolver(new FakeGraphqlClient(self::introspectionResponse()));

        // Act
        $types = $resolver->argumentTypes('unknown');

        // Assert
        self::assertNull($types);
    }

    #[Test]
    public function itIntrospectsTheSchemaOnlyOnceThanksToTheCache(): void
    {
        // Arrange
        $client = new FakeGraphqlClient(self::introspectionResponse());
        $resolver = $this->createResolver($client);

        // Act
        $first = $resolver->argumentTypes('tasks');
        $second = $resolver->argumentTypes('tasks');

        // Assert
        self::assertSame($first, $second);
        self::assertSame(1, $client->callCount);
    }

    #[Test]
    public function itReturnsNullAndLogsAWarningWhenIntrospectionFails(): void
    {
        // Arrange
        $logger = new FakeLogger();
        $client = new GraphqlClient(new MockHttpClient(new MockResponse('', ['http_code' => 500])), 'https://graphql.example.com/graphql');
        $resolver = $this->createResolver($client, $logger);

        // Act
        $types = $resolver->argumentTypes('tasks');

        // Assert
        self::assertNull($types);
        self::assertSame('warning', $logger->records[0]['level'] ?? null);
    }

    #[Test]
    public function itDoesNotRetryAFailedIntrospectionBeforeTheCacheExpires(): void
    {
        // Arrange
        $client = new FakeGraphqlClient(['data' => null], self::introspectionResponse());
        $resolver = $this->createResolver($client);

        // Act
        $resolver->argumentTypes('tasks');
        $types = $resolver->argumentTypes('tasks');

        // Assert
        self::assertNull($types);
        self::assertSame(1, $client->callCount);
    }

    private function createResolver(FakeGraphqlClient|GraphqlClient $client, ?FakeLogger $logger = null): RootArgumentTypeResolver
    {
        return new RootArgumentTypeResolver($client, new ArrayAdapter(), self::TTL, $logger);
    }

    /**
     * @return array<string, mixed>
     */
    private static function introspectionResponse(): array
    {
        $named = fn (string $kind, string $name) => ['kind' => $kind, 'name' => $name, 'ofType' => null];
        $wrapped = fn (string $kind, array $ofType) => ['kind' => $kind, 'name' => null, 'ofType' => $ofType];

        return ['data' => ['__schema' => ['queryType' => ['fields' => [
            ['name' => 'tasks', 'args' => [
                ['name' => 'filter', 'type' => $named('INPUT_OBJECT', 'TaskFilterInput')],
                ['name' => 'ids', 'type' => $wrapped('NON_NULL', $wrapped('LIST', $wrapped('NON_NULL', $named('SCALAR', 'ID'))))],
                ['name' => 'first', 'type' => $named('SCALAR', 'Int')],
            ]],
        ]]]]];
    }
}
