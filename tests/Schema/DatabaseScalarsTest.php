<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Schema;

use GraphqlOrm\Codegen\SchemaPropertyMapper;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Schema\GraphqlSchema;
use GraphqlOrm\Schema\SchemaValidator;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityWithDatabaseScalars;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Scalars named after the database types by Hasura (PostgreSQL) and PostGraphile.
 */
final class DatabaseScalarsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function serverScalars(): iterable
    {
        yield 'Hasura' => ['timestamptz', 'numeric', 'bigint'];
        yield 'Hasura, without time zone' => ['timestamp', 'float8', 'smallint'];
        yield 'PostGraphile' => ['Datetime', 'Float', 'Int'];
    }

    #[Test]
    #[DataProvider('serverScalars')]
    public function itAcceptsTheScalarsOfTheServer(string $date, string $decimal, string $integer): void
    {
        $violations = (new SchemaValidator(new GraphqlEntityMetadataFactory()))->validate(
            [FakeEntityWithDatabaseScalars::class],
            $this->types($date, $decimal, $integer),
        );

        self::assertSame([], $violations);
    }

    #[Test]
    #[DataProvider('serverScalars')]
    public function itGeneratesThePhpTypesOfTheScalars(string $date, string $decimal, string $integer): void
    {
        $properties = [];

        foreach ((new SchemaPropertyMapper())->map(new GraphqlSchema($this->types($date, $decimal, $integer)), 'Invoice') as $property) {
            $properties[$property->name] = $property->phpType;
        }

        self::assertSame(['id' => 'int', 'issuedAt' => '\DateTimeImmutable', 'amount' => 'float', 'lines' => 'int'], $properties);
    }

    /**
     * @return array<string, array{kind: string, fields: array<string, array{kind: string, name: string|null}>}>
     */
    private function types(string $date, string $decimal, string $integer): array
    {
        return [
            'Query' => ['kind' => 'OBJECT', 'fields' => ['invoices' => ['kind' => 'OBJECT', 'name' => 'Invoice']]],
            'Invoice' => ['kind' => 'OBJECT', 'fields' => [
                'id' => ['kind' => 'SCALAR', 'name' => 'Int'],
                'issuedAt' => ['kind' => 'SCALAR', 'name' => $date],
                'amount' => ['kind' => 'SCALAR', 'name' => $decimal],
                'lines' => ['kind' => 'SCALAR', 'name' => $integer],
            ]],
        ];
    }
}
