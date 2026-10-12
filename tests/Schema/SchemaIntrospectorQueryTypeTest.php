<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Schema;

use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchemaIntrospectorQueryTypeTest extends TestCase
{
    /** Wrappers of `[Task!]!`: NON_NULL > LIST > NON_NULL > OBJECT. */
    private const NESTED_WRAPPERS_OF_A_NON_NULL_LIST = 3;

    #[Test]
    public function itReturnsTheQueryTypeNameAlongWithTheTypes(): void
    {
        $client = new FakeGraphqlClient(['data' => ['__schema' => [
            'queryType' => ['name' => 'query_root'],
            'types' => [['name' => 'query_root', 'kind' => 'OBJECT', 'fields' => []]],
        ]]]);

        $schema = (new SchemaIntrospector($client))->introspectSchema();

        self::assertSame('query_root', $schema['queryType']);
        self::assertArrayHasKey('query_root', $schema['types']);
    }

    #[Test]
    public function itDefaultsTheQueryTypeNameToQuery(): void
    {
        $client = new FakeGraphqlClient(['data' => ['__schema' => ['types' => []]]]);

        $schema = (new SchemaIntrospector($client))->introspectSchema();

        self::assertSame('Query', $schema['queryType']);
    }

    #[Test]
    public function itRequestsEnoughTypeWrappersForANonNullListOfNonNullObjects(): void
    {
        $client = new FakeGraphqlClient(['data' => ['__schema' => ['types' => []]]]);

        (new SchemaIntrospector($client))->introspectSchema();

        self::assertGreaterThanOrEqual(self::NESTED_WRAPPERS_OF_A_NON_NULL_LIST, substr_count($client->lastQuery, 'ofType'));
    }
}
