<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Hydrator;

use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityWithBeforeHydrate;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityWithBothHooks;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;
use PHPUnit\Framework\TestCase;

final class BeforeHydrateTest extends TestCase
{
    private GraphqlExecutionContext $context;

    protected function setUp(): void
    {
        $this->context = new GraphqlExecutionContext();
    }

    private function makeHydrator(GraphqlEntityMetadata $metadata): EntityHydrator
    {
        $factory = $this->createStub(GraphqlEntityMetadataFactory::class);
        $factory->method('getMetadata')->willReturn($metadata);

        return new EntityHydrator($factory);
    }

    private function makeMetadata(string $class, array $fields): GraphqlEntityMetadata
    {
        return new GraphqlEntityMetadata($class, 'products', FakeRepository::class, $fields);
    }

    public function testBeforeHydrateMethodIsCalled(): void
    {
        $metadata = $this->makeMetadata(FakeEntityWithBeforeHydrate::class, [
            new GraphqlFieldMetadata(property: 'id', mappedFrom: 'id', isIdentifier: true),
            new GraphqlFieldMetadata(property: 'name', mappedFrom: 'name'),
        ]);

        $entity = $this->makeHydrator($metadata)->hydrate(
            $metadata,
            ['id' => 1, 'name' => 'Widget'],
            $this->context,
        );

        self::assertInstanceOf(FakeEntityWithBeforeHydrate::class, $entity);
        self::assertSame(1, $entity->beforeHydrateCallCount);
    }

    public function testBeforeHydrateReceivesRawData(): void
    {
        $metadata = $this->makeMetadata(FakeEntityWithBeforeHydrate::class, [
            new GraphqlFieldMetadata(property: 'id', mappedFrom: 'id', isIdentifier: true),
            new GraphqlFieldMetadata(property: 'name', mappedFrom: 'name'),
        ]);

        $data = ['id' => 1, 'name' => 'Widget', '__typename' => 'Product'];

        $entity = $this->makeHydrator($metadata)->hydrate(
            $metadata,
            $data,
            $this->context,
        );

        self::assertInstanceOf(FakeEntityWithBeforeHydrate::class, $entity);
        self::assertSame('Product', $entity->rawTypename);
        self::assertSame($data, $entity->receivedData);
    }

    public function testBeforeHydrateIsCalledBeforeFieldsAreAssigned(): void
    {
        $metadata = $this->makeMetadata(FakeEntityWithBeforeHydrate::class, [
            new GraphqlFieldMetadata(property: 'id', mappedFrom: 'id', isIdentifier: true),
            new GraphqlFieldMetadata(property: 'name', mappedFrom: 'name'),
        ]);

        $entity = $this->makeHydrator($metadata)->hydrate(
            $metadata,
            ['id' => 1, 'name' => 'Widget'],
            $this->context,
        );

        self::assertInstanceOf(FakeEntityWithBeforeHydrate::class, $entity);
        self::assertSame('Widget', $entity->name);
        self::assertArrayHasKey('name', $entity->receivedData);
    }

    public function testBeforeHydrateIsCalledOncePerEntity(): void
    {
        $metadata = $this->makeMetadata(FakeEntityWithBeforeHydrate::class, [
            new GraphqlFieldMetadata(property: 'id', mappedFrom: 'id', isIdentifier: true),
            new GraphqlFieldMetadata(property: 'name', mappedFrom: 'name'),
        ]);

        $hydrator = $this->makeHydrator($metadata);

        $entity1 = $hydrator->hydrate($metadata, ['id' => 1, 'name' => 'Widget'], $this->context);
        $entity2 = $hydrator->hydrate($metadata, ['id' => 1, 'name' => 'Widget'], $this->context);

        self::assertSame($entity1, $entity2);
        self::assertInstanceOf(FakeEntityWithBeforeHydrate::class, $entity1);
        self::assertSame(1, $entity1->beforeHydrateCallCount);
    }

    public function testBeforeHydrateRunsBeforeAfterHydrate(): void
    {
        $metadata = $this->makeMetadata(FakeEntityWithBothHooks::class, [
            new GraphqlFieldMetadata(property: 'id', mappedFrom: 'id', isIdentifier: true),
            new GraphqlFieldMetadata(property: 'name', mappedFrom: 'name'),
        ]);

        $entity = $this->makeHydrator($metadata)->hydrate(
            $metadata,
            ['id' => 1, 'name' => 'Widget'],
            $this->context,
        );

        self::assertInstanceOf(FakeEntityWithBothHooks::class, $entity);
        self::assertSame(['before', 'after'], $entity->callOrder);
    }
}
