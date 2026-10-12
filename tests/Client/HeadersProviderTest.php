<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Client;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Tests\Fixtures\FakeHeadersProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HeadersProviderTest extends TestCase
{
    private const ENDPOINT = 'https://graphql.example.com/graphql';
    private const QUERY = 'query { tasks { id } }';

    /** @var list<array<string, list<string>>> */
    private array $sentHeaders = [];

    #[Test]
    public function itAsksTheProviderForHeadersOnEveryRequest(): void
    {
        $provider = new FakeHeadersProvider();
        $client = $this->createClient(staticHeaders: [], provider: $provider);

        $client->query(self::QUERY, new GraphqlExecutionContext());
        $client->query(self::QUERY, new GraphqlExecutionContext());

        self::assertSame(['Authorization: Bearer token-1'], $this->sentHeaders[0]['authorization']);
        self::assertSame(['Authorization: Bearer token-2'], $this->sentHeaders[1]['authorization']);
    }

    #[Test]
    public function itLetsProvidedHeadersOverrideStaticOnes(): void
    {
        $client = $this->createClient(
            staticHeaders: ['Authorization' => 'Bearer static', 'X-Tenant' => 'a'],
            provider: new FakeHeadersProvider(),
        );

        $client->query(self::QUERY, new GraphqlExecutionContext());

        self::assertSame(['Authorization: Bearer token-1'], $this->sentHeaders[0]['authorization']);
        self::assertSame(['X-Tenant: a'], $this->sentHeaders[0]['x-tenant']);
    }

    #[Test]
    public function itSendsStaticHeadersWithoutProvider(): void
    {
        $client = $this->createClient(staticHeaders: ['Authorization' => 'Bearer static'], provider: null);

        $client->query(self::QUERY, new GraphqlExecutionContext());

        self::assertSame(['Authorization: Bearer static'], $this->sentHeaders[0]['authorization']);
    }

    /**
     * @param array<string, string> $staticHeaders
     */
    private function createClient(array $staticHeaders, ?FakeHeadersProvider $provider): GraphqlClient
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) {
            $this->sentHeaders[] = $options['normalized_headers'];

            return new MockResponse('{"data":{"tasks":[]}}');
        });

        return new GraphqlClient($httpClient, self::ENDPOINT, $staticHeaders, headersProvider: $provider);
    }
}
