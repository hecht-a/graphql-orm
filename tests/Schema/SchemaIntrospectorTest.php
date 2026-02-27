<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Schema;

use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\TestCase;

final class SchemaIntrospectorTest extends TestCase
{
    public function testIntrospectReturnsTypeMap(): void
    {
        $client = new FakeGraphqlClient(
            self::makeIntrospectionResponse([
                self::makeType('Product', 'OBJECT', [
                    self::makeField('id', 'NON_NULL', 'SCALAR', 'Int'),
                    self::makeField('name', 'SCALAR', 'SCALAR', 'String'),
                ]),
            ])
        );

        $introspector = new SchemaIntrospector($client);
        $map = $introspector->introspect();

        self::assertArrayHasKey('Product', $map);
        self::assertSame('OBJECT', $map['Product']['kind']);
        self::assertArrayHasKey('id', $map['Product']['fields']);
        self::assertArrayHasKey('name', $map['Product']['fields']);
    }

    public function testUnwrapsNonNullWrapper(): void
    {
        $client = new FakeGraphqlClient(
            self::makeIntrospectionResponse([
                self::makeType('Product', 'OBJECT', [
                    self::makeField('id', 'NON_NULL', 'SCALAR', 'Int'),
                ]),
            ])
        );

        $map = (new SchemaIntrospector($client))->introspect();

        self::assertSame('SCALAR', $map['Product']['fields']['id']['kind']);
        self::assertSame('Int', $map['Product']['fields']['id']['name']);
    }

    public function testUnwrapsListWrapper(): void
    {
        $client = new FakeGraphqlClient(
            self::makeIntrospectionResponse([
                self::makeType('User', 'OBJECT', [
                    self::makeField('tags', 'LIST', 'SCALAR', 'String'),
                ]),
            ])
        );

        $map = (new SchemaIntrospector($client))->introspect();

        self::assertSame('SCALAR', $map['User']['fields']['tags']['kind']);
        self::assertSame('String', $map['User']['fields']['tags']['name']);
    }

    public function testSkipsBuiltInIntrospectionTypes(): void
    {
        $client = new FakeGraphqlClient(
            self::makeIntrospectionResponse([
                self::makeType('__Schema', 'OBJECT', []),
                self::makeType('__Type', 'OBJECT', []),
                self::makeType('Product', 'OBJECT', []),
            ])
        );

        $map = (new SchemaIntrospector($client))->introspect();

        self::assertArrayNotHasKey('__Schema', $map);
        self::assertArrayNotHasKey('__Type', $map);
        self::assertArrayHasKey('Product', $map);
    }

    public function testThrowsOnMissingDataKey(): void
    {
        $client = new FakeGraphqlClient([]);

        $this->expectException(InvalidGraphqlResponseException::class);

        (new SchemaIntrospector($client))->introspect();
    }

    public function testThrowsOnMissingSchemaKey(): void
    {
        $client = new FakeGraphqlClient(['data' => []]);

        $this->expectException(InvalidGraphqlResponseException::class);

        (new SchemaIntrospector($client))->introspect();
    }

    public function testThrowsOnMissingTypesKey(): void
    {
        $client = new FakeGraphqlClient(['data' => ['__schema' => []]]);

        $this->expectException(InvalidGraphqlResponseException::class);

        (new SchemaIntrospector($client))->introspect();
    }

    public function testHandlesTypeWithNullFields(): void
    {
        $client = new FakeGraphqlClient(
            self::makeIntrospectionResponse([
                ['name' => 'String', 'kind' => 'SCALAR', 'fields' => null],
            ])
        );

        $map = (new SchemaIntrospector($client))->introspect();

        self::assertArrayHasKey('String', $map);
        self::assertSame([], $map['String']['fields']);
    }

    public function testSendsIntrospectionQuery(): void
    {
        $client = new FakeGraphqlClient(
            self::makeIntrospectionResponse([])
        );

        (new SchemaIntrospector($client))->introspect();

        self::assertStringContainsString('__schema', $client->lastQuery);
        self::assertStringContainsString('IntrospectionQuery', $client->lastQuery);
    }

    /**
     * @param array<int, array<string, mixed>> $types
     *
     * @return array<string, mixed>
     */
    private static function makeIntrospectionResponse(array $types): array
    {
        return [
            'data' => [
                '__schema' => [
                    'types' => $types,
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     *
     * @return array<string, mixed>
     */
    private static function makeType(string $name, string $kind, array $fields): array
    {
        return [
            'name' => $name,
            'kind' => $kind,
            'fields' => $fields,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function makeField(string $name, string $wrapperKind, string $leafKind, string $leafName): array
    {
        $leafType = ['name' => $leafName, 'kind' => $leafKind, 'ofType' => null];

        $type = $wrapperKind === $leafKind
            ? $leafType
            : ['name' => null, 'kind' => $wrapperKind, 'ofType' => $leafType];

        return ['name' => $name, 'type' => $type];
    }
}
