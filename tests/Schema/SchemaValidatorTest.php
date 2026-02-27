<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Schema;

use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Schema\SchemaValidator;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityWithUnknownField;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityWithUnknownType;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityWithWrongScalar;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTaskWithTypedRelation;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeValidEntity;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorTest extends TestCase
{
    private SchemaValidator $validator;

    /** @var array<string, array{kind: string, fields: array<string, array{kind: string, name: string|null}>}> */
    private array $schemaTypes;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator(new GraphqlEntityMetadataFactory());

        $this->schemaTypes = [
            'Product' => [
                'kind' => 'OBJECT',
                'fields' => [
                    'id' => ['kind' => 'SCALAR', 'name' => 'Int'],
                    'name' => ['kind' => 'SCALAR', 'name' => 'String'],
                    'price' => ['kind' => 'SCALAR', 'name' => 'Float'],
                    'active' => ['kind' => 'SCALAR', 'name' => 'Boolean'],
                ],
            ],
        ];
    }

    public function testNoViolationsForValidEntity(): void
    {
        $violations = $this->validator->validate(
            [FakeValidEntity::class],
            $this->schemaTypes
        );

        self::assertSame([], $violations);
    }

    public function testViolationWhenGraphqlTypeNotFound(): void
    {
        $violations = $this->validator->validate(
            [FakeEntityWithUnknownType::class],
            $this->schemaTypes
        );

        self::assertCount(1, $violations);
        self::assertStringContainsString('ghosts', $violations[0]);
        self::assertStringContainsString('not found in schema', $violations[0]);
    }

    public function testViolationWhenFieldNotFoundOnType(): void
    {
        $violations = $this->validator->validate(
            [FakeEntityWithUnknownField::class],
            $this->schemaTypes
        );

        self::assertCount(1, $violations);
        self::assertStringContainsString('doesNotExist', $violations[0]);
        self::assertStringContainsString('Product', $violations[0]);
    }

    public function testSuggestsClosestFieldOnViolation(): void
    {
        $schemaTypes = [
            'Product' => [
                'kind' => 'OBJECT',
                'fields' => [
                    'id' => ['kind' => 'SCALAR', 'name' => 'Int'],
                    'name' => ['kind' => 'SCALAR', 'name' => 'String'],
                ],
            ],
        ];

        $metadata = new GraphqlEntityMetadata(
            FakeEntityWithUnknownField::class,
            'products',
            null,
            [
                new GraphqlFieldMetadata(
                    property: 'id',
                    mappedFrom: 'id',
                    isIdentifier: true,
                ),
                new GraphqlFieldMetadata(
                    property: 'nme',
                    mappedFrom: 'nme',
                ),
            ],
        );

        $factory = $this->createStub(GraphqlEntityMetadataFactory::class);
        $factory->method('getMetadata')->willReturn($metadata);

        $validator = new SchemaValidator($factory);
        $violations = $validator->validate(
            [FakeEntityWithUnknownField::class],
            $schemaTypes
        );

        self::assertCount(1, $violations);
        self::assertStringContainsString('nme', $violations[0]);
        self::assertStringContainsString('Did you mean "name"', $violations[0]);
    }

    public function testNoSuggestionWhenFieldIsTooFarFromAllCandidates(): void
    {
        $metadata = new GraphqlEntityMetadata(
            FakeEntityWithUnknownField::class,
            'products',
            null,
            [
                new GraphqlFieldMetadata(
                    property: 'wxyz',
                    mappedFrom: 'wxyz',
                    isIdentifier: false,
                ),
            ],
        );

        $factory = $this->createStub(GraphqlEntityMetadataFactory::class);
        $factory->method('getMetadata')->willReturn($metadata);

        $validator = new SchemaValidator($factory);
        $violations = $validator->validate(
            [FakeEntityWithUnknownField::class],
            $this->schemaTypes
        );

        self::assertCount(1, $violations);
        self::assertStringNotContainsString('Did you mean', $violations[0]);
    }

    public function testViolationWhenScalarTypeMismatch(): void
    {
        $violations = $this->validator->validate(
            [FakeEntityWithWrongScalar::class],
            $this->schemaTypes
        );

        self::assertCount(1, $violations);
        self::assertStringContainsString('price', $violations[0]);
        self::assertStringContainsString('Float', $violations[0]);
        self::assertStringContainsString('string', $violations[0]);
    }

    public function testMultipleEntitiesAreAllValidated(): void
    {
        $violations = $this->validator->validate(
            [FakeEntityWithUnknownType::class, FakeEntityWithUnknownField::class],
            $this->schemaTypes
        );

        self::assertCount(2, $violations);
    }

    public function testEmptyEntityListProducesNoViolations(): void
    {
        $violations = $this->validator->validate([], $this->schemaTypes);

        self::assertSame([], $violations);
    }

    public function testRelationFieldIsNotCheckedAsScalar(): void
    {
        $schemaWithRelation = [
            'Task' => [
                'kind' => 'OBJECT',
                'fields' => [
                    'id' => ['kind' => 'SCALAR', 'name' => 'Int'],
                    'title' => ['kind' => 'SCALAR', 'name' => 'String'],
                    'user' => ['kind' => 'OBJECT', 'name' => 'User'],
                ],
            ],
            'User' => [
                'kind' => 'OBJECT',
                'fields' => [
                    'id' => ['kind' => 'SCALAR', 'name' => 'Int'],
                ],
            ],
        ];

        $violations = $this->validator->validate(
            [FakeTaskWithTypedRelation::class],
            $schemaWithRelation
        );

        self::assertSame([], $violations);
    }

    public function testTypeResolutionHandlesPluralRootName(): void
    {
        $violations = $this->validator->validate(
            [FakeValidEntity::class],
            $this->schemaTypes
        );

        self::assertSame([], $violations);
    }

    public function testSchemaFieldNotInEntityIsNotAViolation(): void
    {
        $schemaWithExtraField = $this->schemaTypes;
        $schemaWithExtraField['Product']['fields']['createdAt'] = ['kind' => 'SCALAR', 'name' => 'String'];

        $violations = $this->validator->validate(
            [FakeValidEntity::class],
            $schemaWithExtraField
        );

        self::assertSame([], $violations);
    }
}
