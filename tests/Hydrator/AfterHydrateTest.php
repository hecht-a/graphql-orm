<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Hydrator;

use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityWithAfterHydrate;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityWithMultipleAfterHydrate;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;
use PHPUnit\Framework\TestCase;

final class AfterHydrateTest extends TestCase
{
    private EntityHydrator $hydrator;

    private GraphqlEntityMetadata $metadata;

    private GraphqlExecutionContext $context;

    protected function setUp(): void
    {
        $this->metadata = new GraphqlEntityMetadata(
            FakeEntityWithAfterHydrate::class,
            'products',
            FakeRepository::class,
            [
                new GraphqlFieldMetadata(property: 'id', mappedFrom: 'id', isIdentifier: true),
                new GraphqlFieldMetadata(property: 'price', mappedFrom: 'price'),
                new GraphqlFieldMetadata(property: 'taxRate', mappedFrom: 'taxRate'),
            ],
        );

        $factory = $this->createStub(GraphqlEntityMetadataFactory::class);
        $factory->method('getMetadata')->willReturn($this->metadata);

        $this->hydrator = new EntityHydrator($factory);
        $this->context = new GraphqlExecutionContext();
    }

    public function testAfterHydrateMethodIsCalled(): void
    {
        $entity = $this->hydrator->hydrate(
            $this->metadata,
            ['id' => 1, 'price' => 100.0, 'taxRate' => 20.0],
            $this->context,
        );

        self::assertInstanceOf(FakeEntityWithAfterHydrate::class, $entity);
        self::assertSame(1, $entity->afterHydrateCallCount);
    }

    public function testAfterHydrateComputesVirtualField(): void
    {
        $entity = $this->hydrator->hydrate(
            $this->metadata,
            ['id' => 1, 'price' => 100.0, 'taxRate' => 20.0],
            $this->context,
        );

        self::assertInstanceOf(FakeEntityWithAfterHydrate::class, $entity);
        self::assertEqualsWithDelta(120.0, $entity->priceWithTax, 0.001);
    }

    public function testAfterHydrateIsCalledOncePerEntity(): void
    {
        $entity1 = $this->hydrator->hydrate(
            $this->metadata,
            ['id' => 1, 'price' => 100.0, 'taxRate' => 20.0],
            $this->context,
        );

        $entity2 = $this->hydrator->hydrate(
            $this->metadata,
            ['id' => 1, 'price' => 100.0, 'taxRate' => 20.0],
            $this->context,
        );

        self::assertSame($entity1, $entity2);
        self::assertInstanceOf(FakeEntityWithAfterHydrate::class, $entity1);
        self::assertSame(1, $entity1->afterHydrateCallCount);
    }

    public function testAfterHydrateIsSkippedWhenMappedFieldsMissing(): void
    {
        $entity = $this->hydrator->hydrate(
            $this->metadata,
            ['id' => 1],
            $this->context,
        );

        self::assertInstanceOf(FakeEntityWithAfterHydrate::class, $entity);
        self::assertSame(0, $entity->afterHydrateCallCount);
        self::assertEqualsWithDelta(0.0, $entity->priceWithTax, 0.001);
    }

    public function testVirtualFieldDoesNotBlockAfterHydrate(): void
    {
        $entity = $this->hydrator->hydrate(
            $this->metadata,
            ['id' => 1, 'price' => 50.0, 'taxRate' => 10.0],
            $this->context,
        );

        self::assertInstanceOf(FakeEntityWithAfterHydrate::class, $entity);
        self::assertSame(1, $entity->afterHydrateCallCount);
        self::assertEqualsWithDelta(55.0, $entity->priceWithTax, 0.001);
    }

    public function testMultipleAfterHydrateMethods(): void
    {
        $metadata = new GraphqlEntityMetadata(
            FakeEntityWithMultipleAfterHydrate::class,
            'products',
            FakeRepository::class,
            [
                new GraphqlFieldMetadata(property: 'id', mappedFrom: 'id', isIdentifier: true),
                new GraphqlFieldMetadata(property: 'price', mappedFrom: 'price'),
            ],
        );

        $factory = $this->createStub(GraphqlEntityMetadataFactory::class);
        $factory->method('getMetadata')->willReturn($metadata);

        $hydrator = new EntityHydrator($factory);

        $entity = $hydrator->hydrate(
            $metadata,
            ['id' => 1, 'price' => 100.0],
            $this->context,
        );

        self::assertInstanceOf(FakeEntityWithMultipleAfterHydrate::class, $entity);
        self::assertSame(2, $entity->callCount);
    }

    public function testEntityWithoutAfterHydrateHydratesNormally(): void
    {
        $metadata = new GraphqlEntityMetadata(
            FakeTask::class,
            'tasks',
            FakeRepository::class,
            [
                new GraphqlFieldMetadata(property: 'id', mappedFrom: 'id', isIdentifier: true),
                new GraphqlFieldMetadata(property: 'title', mappedFrom: 'title'),
            ],
        );

        $factory = $this->createStub(GraphqlEntityMetadataFactory::class);
        $factory->method('getMetadata')->willReturn($metadata);

        $hydrator = new EntityHydrator($factory);

        $entity = $hydrator->hydrate(
            $metadata,
            ['id' => 1, 'title' => 'Test'],
            $this->context,
        );

        self::assertInstanceOf(FakeTask::class, $entity);
        self::assertSame(1, $entity->id);
        self::assertSame('Test', $entity->title);
    }
}
