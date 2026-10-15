<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Schema;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Schema\CountableFieldResolver;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Tests\Fixtures\FakeDataApiBuilderSchema;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The introspection queries of the bundle are sent without the manager: they record themselves in the profiler.
 */
final class IntrospectionTracingTest extends TestCase
{
    private const TTL = 3600;

    private GraphqlOrmDataCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new GraphqlOrmDataCollector();
    }

    #[Test]
    public function itRecordsTheIntrospectionOfTheArgumentTypes(): void
    {
        $client = new FakeGraphqlClient(['data' => ['__schema' => ['queryType' => ['fields' => []]]]]);

        (new RootArgumentTypeResolver($client, new ArrayAdapter(), self::TTL, collector: $this->collector))->argumentTypes('tasks');

        $this->assertIntrospectionRecorded();
    }

    #[Test]
    public function itRecordsTheIntrospectionOfTheCountableFields(): void
    {
        $client = new FakeGraphqlClient(FakeDataApiBuilderSchema::introspectionResponse());

        (new CountableFieldResolver($client, new ArrayAdapter(), self::TTL, collector: $this->collector))->countableFields('labels');

        $this->assertIntrospectionRecorded();
    }

    #[Test]
    public function itRecordsTheIntrospectionOfTheSchemaValidation(): void
    {
        $client = new FakeGraphqlClient(['data' => ['__schema' => ['queryType' => ['name' => 'Query'], 'types' => []]]]);

        (new SchemaIntrospector($client, $this->collector))->introspect();

        $this->assertIntrospectionRecorded();
    }

    private function assertIntrospectionRecorded(): void
    {
        $queries = $this->collector->getQueries();

        self::assertCount(1, $queries);
        self::assertSame('introspection', $queries[0]['origin'] ?? null);
        self::assertStringContainsString('__schema', \is_string($queries[0]['graphql'] ?? null) ? $queries[0]['graphql'] : '');
        self::assertIsFloat($queries[0]['started_at'] ?? null);
    }
}
