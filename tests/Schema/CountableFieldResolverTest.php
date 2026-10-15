<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Schema;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Schema\CountableFieldResolver;
use GraphqlOrm\Tests\Fixtures\FakeDataApiBuilderSchema;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Fixtures\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CountableFieldResolverTest extends TestCase
{
    private const TTL = 3600;

    #[Test]
    public function itListsTheNumericFieldsThatAreNeverNull(): void
    {
        $resolver = $this->createResolver(new FakeGraphqlClient(FakeDataApiBuilderSchema::introspectionResponse()));

        self::assertSame(['position', 'weight'], $resolver->countableFields('labels'));
    }

    #[Test]
    public function itListsNoFieldWhenEveryNumericFieldMayBeNull(): void
    {
        $resolver = $this->createResolver(new FakeGraphqlClient(FakeDataApiBuilderSchema::introspectionResponse()));

        self::assertSame([], $resolver->countableFields('notes'));
    }

    #[Test]
    public function itReturnsNullForARootFieldThatIsNoConnection(): void
    {
        $resolver = $this->createResolver(new FakeGraphqlClient(FakeDataApiBuilderSchema::introspectionResponse()));

        self::assertNull($resolver->countableFields('label_by_pk'));
        self::assertNull($resolver->countableFields('unknown'));
    }

    #[Test]
    public function itIntrospectsTheSchemaOnlyOnceThanksToTheCache(): void
    {
        $client = new FakeGraphqlClient(FakeDataApiBuilderSchema::introspectionResponse());
        $resolver = $this->createResolver($client);

        $resolver->countableFields('labels');
        $resolver->countableFields('notes');

        self::assertSame(1, $client->callCount);
    }

    #[Test]
    public function itReturnsNullAndLogsAWarningWhenIntrospectionFails(): void
    {
        $logger = new FakeLogger();
        $client = new GraphqlClient(new MockHttpClient(new MockResponse('', ['http_code' => 500])), 'https://graphql.example.com/graphql');
        $resolver = $this->createResolver($client, $logger);

        self::assertNull($resolver->countableFields('labels'));
        self::assertSame('warning', $logger->records[0]['level'] ?? null);
    }

    private function createResolver(FakeGraphqlClient|GraphqlClient $client, ?FakeLogger $logger = null): CountableFieldResolver
    {
        return new CountableFieldResolver($client, new ArrayAdapter(), self::TTL, $logger);
    }
}
