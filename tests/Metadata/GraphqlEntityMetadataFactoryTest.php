<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Metadata;

use GraphqlOrm\Exception\NotAGraphqlEntityException;
use GraphqlOrm\Exception\TooManyIdentifiersException;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Tests\Fixtures\FakeEntity\EntityWithMultipleIdentifiers;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityWithIgnoredProperty;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTaskWithExplicitRelation;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTaskWithTypedRelation;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTaskWithUnionRelation;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeUser;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeUserWithCollection;
use GraphqlOrm\Tests\Fixtures\FakeEntity\NotAnEntity;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;
use PHPUnit\Framework\TestCase;

final class GraphqlEntityMetadataFactoryTest extends TestCase
{
    public function testGetMetadataBuildsScalarFields(): void
    {
        $factory = new GraphqlEntityMetadataFactory();

        $metadata = $factory->getMetadata(FakeTask::class);

        self::assertSame(FakeTask::class, $metadata->class);
        self::assertSame('tasks', $metadata->name);
        self::assertSame(FakeRepository::class, $metadata->repositoryClass);

        self::assertCount(2, $metadata->fields);

        self::assertSame('id', $metadata->identifier?->property);
        self::assertSame('id', $metadata->identifier?->mappedFrom);
    }

    public function testRelationDetectedFromTargetEntityAttribute(): void
    {
        $factory = new GraphqlEntityMetadataFactory();

        $metadata = $factory->getMetadata(FakeTaskWithExplicitRelation::class);

        $userField = $this->findField($metadata->fields, 'user');

        self::assertSame(FakeUser::class, $userField->relation);
        self::assertFalse($userField->isCollection);
    }

    public function testRelationDetectedFromTypedProperty(): void
    {
        $factory = new GraphqlEntityMetadataFactory();

        $metadata = $factory->getMetadata(FakeTaskWithTypedRelation::class);

        $userField = $this->findField($metadata->fields, 'user');

        self::assertSame(FakeUser::class, $userField->relation);
    }

    public function testUnionTypeRelationDetection(): void
    {
        $factory = new GraphqlEntityMetadataFactory();

        $metadata = $factory->getMetadata(FakeTaskWithUnionRelation::class);

        $userField = $this->findField($metadata->fields, 'user');

        self::assertSame(FakeUser::class, $userField->relation);
    }

    public function testArrayPropertyIsDetectedAsCollection(): void
    {
        $factory = new GraphqlEntityMetadataFactory();

        $metadata = $factory->getMetadata(FakeUserWithCollection::class);

        $tasksField = $this->findField($metadata->fields, 'tasks');

        self::assertTrue($tasksField->isCollection);
    }

    public function testPropertyWithoutGraphqlFieldIsIgnored(): void
    {
        $factory = new GraphqlEntityMetadataFactory();

        $metadata = $factory->getMetadata(FakeEntityWithIgnoredProperty::class);

        self::assertCount(1, $metadata->fields);
    }

    public function testThrowsWhenNoGraphqlEntityAttribute(): void
    {
        $this->expectException(NotAGraphqlEntityException::class);

        (new GraphqlEntityMetadataFactory())->getMetadata(NotAnEntity::class);
    }

    public function testThrowsWhenMultipleIdentifiers(): void
    {
        $this->expectException(TooManyIdentifiersException::class);

        (new GraphqlEntityMetadataFactory())->getMetadata(EntityWithMultipleIdentifiers::class);
    }

    public function testMetadataIsCached(): void
    {
        $factory = new GraphqlEntityMetadataFactory();

        $a = $factory->getMetadata(FakeTask::class);
        $b = $factory->getMetadata(FakeTask::class);

        self::assertSame($a, $b);
    }

    private function findField(array $fields, string $property)
    {
        foreach ($fields as $field) {
            if ($field->property === $property) {
                return $field;
            }
        }

        self::fail("Field {$property} not found.");
    }
}
