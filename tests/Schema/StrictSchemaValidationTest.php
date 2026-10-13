<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Schema;

use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Schema\SchemaValidator;
use GraphqlOrm\Tests\Fixtures\Strict\StrictTask;
use GraphqlOrm\Tests\Fixtures\Strict\StrictUser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StrictSchemaValidationTest extends TestCase
{
    #[Test]
    public function itAcceptsAMappingMatchingTheSchema(): void
    {
        self::assertSame([], $this->validate([]));
    }

    #[Test]
    public function itReportsANonNullablePropertyMappedOnANullableField(): void
    {
        $violations = $this->validate(['StrictTask' => ['title' => self::field('SCALAR', 'String', nullable: true)]]);

        self::assertSame([\sprintf('[%s] Field "title" is nullable in the schema but property "title" does not accept null: declare it as "?string".', StrictTask::class)], $violations);
    }

    #[Test]
    public function itIgnoresNullabilityWhenTheSchemaDoesNotTellIt(): void
    {
        self::assertSame([], $this->validate(['StrictTask' => ['title' => ['kind' => 'SCALAR', 'name' => 'String']]]));
    }

    #[Test]
    public function itReportsARelationToAnotherType(): void
    {
        $violations = $this->validate(['StrictTask' => ['owner' => self::field('OBJECT', 'Comment')]], ['Comment' => ['kind' => 'OBJECT', 'fields' => []]]);

        self::assertSame([\sprintf('[%s] Field "owner" is a relation to %s (GraphQL type "StrictUser") but returns the GraphQL type "Comment".', StrictTask::class, StrictUser::class)], $violations);
    }

    #[Test]
    public function itReportsARelationMappedOnAScalar(): void
    {
        $violations = $this->validate(['StrictTask' => ['owner' => self::field('SCALAR', 'Int')]]);

        self::assertSame([\sprintf('[%s] Field "owner" is a relation to %s but is a SCALAR in the schema.', StrictTask::class, StrictUser::class)], $violations);
    }

    #[Test]
    public function itReportsACollectionMappedOnASingleObject(): void
    {
        $violations = $this->validate(['StrictTask' => ['members' => self::field('OBJECT', 'StrictUser')]]);

        self::assertSame([\sprintf('[%s] Field "members" is a collection but returns a single object in the schema: map it on a single relation.', StrictTask::class)], $violations);
    }

    #[Test]
    public function itReportsASingleRelationMappedOnAList(): void
    {
        $violations = $this->validate(['StrictTask' => ['owner' => self::field('OBJECT', 'StrictUser', list: true)]]);

        self::assertSame([\sprintf('[%s] Field "owner" is a single relation but returns a list in the schema: map it on a collection (array property).', StrictTask::class)], $violations);
    }

    #[Test]
    public function itAcceptsACollectionMappedOnAConnection(): void
    {
        $violations = $this->validate(
            ['StrictTask' => ['members' => self::field('OBJECT', 'StrictUserConnection')]],
            ['StrictUserConnection' => ['kind' => 'OBJECT', 'fields' => ['items' => self::field('OBJECT', 'StrictUser', nullable: false, list: true)]]],
        );

        self::assertSame([], $violations);
    }

    /**
     * @param array<string, array<string, array{kind: string, name: string|null, nullable?: bool, list?: bool}>>                              $overrides  fields replaced per type
     * @param array<string, array{kind: string, fields: array<string, array{kind: string, name: string|null, nullable?: bool, list?: bool}>}> $extraTypes
     *
     * @return string[]
     */
    private function validate(array $overrides, array $extraTypes = []): array
    {
        $types = [
            'Query' => ['kind' => 'OBJECT', 'fields' => [
                'strictTasks' => self::field('OBJECT', 'StrictTask', list: true),
                'strictUsers' => self::field('OBJECT', 'StrictUser', list: true),
            ]],
            'StrictTask' => ['kind' => 'OBJECT', 'fields' => [
                'id' => self::field('SCALAR', 'Int', nullable: false),
                'title' => self::field('SCALAR', 'String', nullable: false),
                'owner' => self::field('OBJECT', 'StrictUser'),
                'members' => self::field('OBJECT', 'StrictUser', list: true),
            ]],
            'StrictUser' => ['kind' => 'OBJECT', 'fields' => [
                'id' => self::field('SCALAR', 'Int', nullable: false),
                'name' => self::field('SCALAR', 'String'),
            ]],
            ...$extraTypes,
        ];

        foreach ($overrides as $type => $fields) {
            $types[$type] = ['kind' => $types[$type]['kind'] ?? 'OBJECT', 'fields' => [...$types[$type]['fields'] ?? [], ...$fields]];
        }

        return (new SchemaValidator(new GraphqlEntityMetadataFactory()))->validate([StrictTask::class, StrictUser::class], $types);
    }

    /**
     * @return array{kind: string, name: string, nullable: bool, list: bool}
     */
    private static function field(string $kind, string $name, bool $nullable = true, bool $list = false): array
    {
        return ['kind' => $kind, 'name' => $name, 'nullable' => $nullable, 'list' => $list];
    }
}
