<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Codegen;

use GraphqlOrm\Codegen\PropertyDefinition;
use GraphqlOrm\Codegen\SchemaPropertyMapper;
use GraphqlOrm\Schema\GraphqlSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchemaPropertyMapperTest extends TestCase
{
    private SchemaPropertyMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new SchemaPropertyMapper();
    }

    #[Test]
    public function itMapsScalarsToPhpTypesWithTheirNullability(): void
    {
        $schema = $this->schema([
            'id' => self::field('SCALAR', 'Int', nullable: false),
            'title' => self::field('SCALAR', 'String'),
            'price' => self::field('SCALAR', 'Decimal'),
            'done' => self::field('SCALAR', 'Boolean', nullable: false),
            'createdAt' => self::field('SCALAR', 'DateTime'),
            'status' => self::field('ENUM', 'Status'),
            'reference' => self::field('SCALAR', 'UUID'),
        ]);

        $properties = $this->byName($this->mapper->map($schema, 'Task'));

        self::assertSame(['int', false], [$properties['id']->phpType, $properties['id']->nullable]);
        self::assertSame(['string', true], [$properties['title']->phpType, $properties['title']->nullable]);
        self::assertSame('float', $properties['price']->phpType);
        self::assertSame(['bool', false], [$properties['done']->phpType, $properties['done']->nullable]);
        self::assertSame('\DateTimeImmutable', $properties['createdAt']->phpType);
        self::assertSame('string', $properties['status']->phpType);
        self::assertSame('string', $properties['reference']->phpType);
    }

    #[Test]
    public function itMarksTheIdFieldAsIdentifier(): void
    {
        $schema = $this->schema(['title' => self::field('SCALAR', 'String'), 'id' => self::field('SCALAR', 'ID', nullable: false)]);

        $properties = $this->byName($this->mapper->map($schema, 'Task'));

        self::assertTrue($properties['id']->identifier);
        self::assertFalse($properties['title']->identifier);
    }

    #[Test]
    public function itFallsBackOnTheFirstIdFieldAsIdentifier(): void
    {
        $schema = $this->schema(['code' => self::field('SCALAR', 'ID', nullable: false), 'other' => self::field('SCALAR', 'ID')]);

        $properties = $this->byName($this->mapper->map($schema, 'Task'));

        self::assertTrue($properties['code']->identifier);
        self::assertFalse($properties['other']->identifier);
    }

    #[Test]
    public function itMapsObjectsToRelationsAndListsToCollections(): void
    {
        $schema = $this->schema([
            'owner' => self::field('OBJECT', 'User'),
            'tags' => self::field('OBJECT', 'Tag', list: true),
        ], ['User' => ['kind' => 'OBJECT', 'fields' => []], 'Tag' => ['kind' => 'OBJECT', 'fields' => []]]);

        $properties = $this->byName($this->mapper->map($schema, 'Task'));

        self::assertSame([true, false, 'User'], [$properties['owner']->relation, $properties['owner']->collection, $properties['owner']->targetEntity]);
        self::assertSame([true, true, 'Tag'], [$properties['tags']->relation, $properties['tags']->collection, $properties['tags']->targetEntity]);
    }

    #[Test]
    public function itNamesTheTargetsOfTableNamedTypesAsClasses(): void
    {
        $schema = $this->schema([
            'owner' => self::field('OBJECT', 'users'),
            'categories' => self::field('OBJECT', 'task_categories', list: true),
            'tag' => self::field('OBJECT', 'Tag'),
        ], [
            'users' => ['kind' => 'OBJECT', 'fields' => []],
            'task_categories' => ['kind' => 'OBJECT', 'fields' => []],
            'Tag' => ['kind' => 'OBJECT', 'fields' => []],
        ]);

        $properties = $this->byName($this->mapper->map($schema, 'Task'));

        self::assertSame(['User', 'TaskCategory', 'Tag'], [
            $properties['owner']->targetEntity,
            $properties['categories']->targetEntity,
            $properties['tag']->targetEntity,
        ]);
    }

    #[Test]
    public function itMapsAConnectionToACollectionOfItsItems(): void
    {
        $schema = $this->schema(['comments' => self::field('OBJECT', 'CommentConnection')], [
            'CommentConnection' => ['kind' => 'OBJECT', 'fields' => ['items' => self::field('OBJECT', 'Comment', nullable: false, list: true)]],
            'Comment' => ['kind' => 'OBJECT', 'fields' => []],
        ]);

        $properties = $this->byName($this->mapper->map($schema, 'Task'));

        self::assertSame([true, true, 'Comment'], [$properties['comments']->relation, $properties['comments']->collection, $properties['comments']->targetEntity]);
    }

    #[Test]
    public function itSkipsTheTypenameAndMapsScalarListsToArrays(): void
    {
        $schema = $this->schema([
            '__typename' => self::field('SCALAR', 'String', nullable: false),
            'labels' => self::field('SCALAR', 'String', list: true),
            'title' => self::field('SCALAR', 'String'),
        ]);

        $properties = $this->byName($this->mapper->map($schema, 'Task'));

        self::assertSame(['labels', 'title'], array_keys($properties));
        self::assertSame(['array', false], [$properties['labels']->phpType, $properties['labels']->relation]);
    }

    /**
     * @param array<string, array{kind: string, name: string|null, nullable: bool, list: bool}>                 $taskFields
     * @param array<string, array{kind: string, fields: array<string, array{kind: string, name: string|null}>}> $otherTypes
     */
    private function schema(array $taskFields, array $otherTypes = []): GraphqlSchema
    {
        return new GraphqlSchema(['Task' => ['kind' => 'OBJECT', 'fields' => $taskFields], ...$otherTypes]);
    }

    /**
     * @return array{kind: string, name: string|null, nullable: bool, list: bool}
     */
    private static function field(string $kind, string $name, bool $nullable = true, bool $list = false): array
    {
        return ['kind' => $kind, 'name' => $name, 'nullable' => $nullable, 'list' => $list];
    }

    /**
     * @param PropertyDefinition[] $properties
     *
     * @return array<string, PropertyDefinition>
     */
    private function byName(array $properties): array
    {
        $byName = [];

        foreach ($properties as $property) {
            $byName[$property->name] = $property;
        }

        return $byName;
    }
}
