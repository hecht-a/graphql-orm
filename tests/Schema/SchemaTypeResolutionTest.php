<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Schema;

use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Schema\SchemaValidator;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeAddress;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityWithScalars;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchemaTypeResolutionTest extends TestCase
{
    private const POSTAL_ADDRESS = [
        'kind' => 'OBJECT',
        'fields' => [
            'id' => ['kind' => 'SCALAR', 'name' => 'Int'],
            'city' => ['kind' => 'SCALAR', 'name' => 'String'],
        ],
    ];

    private SchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator(new GraphqlEntityMetadataFactory());
    }

    #[Test]
    public function itResolvesTheEntityTypeFromTheRootQueryField(): void
    {
        // Arrange
        $schemaTypes = [
            'Query' => ['kind' => 'OBJECT', 'fields' => ['addresses' => ['kind' => 'OBJECT', 'name' => 'PostalAddress']]],
            'PostalAddress' => self::POSTAL_ADDRESS,
        ];

        // Act
        $violations = $this->validator->validate([FakeAddress::class], $schemaTypes);

        // Assert
        self::assertSame([], $violations);
    }

    #[Test]
    public function itResolvesTheItemTypeOfAConnectionRootField(): void
    {
        // Arrange
        $schemaTypes = [
            'Query' => ['kind' => 'OBJECT', 'fields' => ['addresses' => ['kind' => 'OBJECT', 'name' => 'PostalAddressConnection']]],
            'PostalAddressConnection' => [
                'kind' => 'OBJECT',
                'fields' => [
                    'items' => ['kind' => 'OBJECT', 'name' => 'PostalAddress'],
                    'hasNextPage' => ['kind' => 'SCALAR', 'name' => 'Boolean'],
                    'endCursor' => ['kind' => 'SCALAR', 'name' => 'String'],
                ],
            ],
            'PostalAddress' => self::POSTAL_ADDRESS,
        ];

        // Act
        $violations = $this->validator->validate([FakeAddress::class], $schemaTypes);

        // Assert
        self::assertSame([], $violations);
    }

    #[Test]
    public function itUsesTheGivenQueryTypeName(): void
    {
        // Arrange
        $schemaTypes = [
            'query_root' => ['kind' => 'OBJECT', 'fields' => ['addresses' => ['kind' => 'OBJECT', 'name' => 'PostalAddress']]],
            'PostalAddress' => self::POSTAL_ADDRESS,
        ];

        // Act
        $violations = $this->validator->validate([FakeAddress::class], $schemaTypes, 'query_root');

        // Assert
        self::assertSame([], $violations);
    }

    #[Test]
    public function itReportsAnUnknownFieldOnTheResolvedType(): void
    {
        // Arrange
        $schemaTypes = [
            'Query' => ['kind' => 'OBJECT', 'fields' => ['addresses' => ['kind' => 'OBJECT', 'name' => 'PostalAddress']]],
            'PostalAddress' => ['kind' => 'OBJECT', 'fields' => ['id' => ['kind' => 'SCALAR', 'name' => 'Int']]],
        ];

        // Act
        $violations = $this->validator->validate([FakeAddress::class], $schemaTypes);

        // Assert
        self::assertCount(1, $violations);
        self::assertStringContainsString('"city"', $violations[0]);
        self::assertStringContainsString('"PostalAddress"', $violations[0]);
    }

    #[Test]
    public function itAcceptsDataApiBuilderScalars(): void
    {
        // Arrange
        $schemaTypes = [
            'Query' => ['kind' => 'OBJECT', 'fields' => ['scalars' => ['kind' => 'OBJECT', 'name' => 'Measure']]],
            'Measure' => [
                'kind' => 'OBJECT',
                'fields' => [
                    'id' => ['kind' => 'SCALAR', 'name' => 'Long'],
                    'price' => ['kind' => 'SCALAR', 'name' => 'Decimal'],
                    'active' => ['kind' => 'SCALAR', 'name' => 'Boolean'],
                    'label' => ['kind' => 'SCALAR', 'name' => 'String'],
                ],
            ],
        ];

        // Act
        $violations = $this->validator->validate([FakeEntityWithScalars::class], $schemaTypes);

        // Assert
        self::assertSame([], $violations);
    }
}
