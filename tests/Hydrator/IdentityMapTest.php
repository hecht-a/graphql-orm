<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Hydrator;

use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityWithAfterHydrate;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityWithIdentifierNotFirst;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IdentityMapTest extends TestCase
{
    private EntityHydrator $hydrator;

    private GraphqlEntityMetadataFactory $metadataFactory;

    protected function setUp(): void
    {
        $this->metadataFactory = new GraphqlEntityMetadataFactory();
        $this->hydrator = new EntityHydrator($this->metadataFactory);
    }

    #[Test]
    public function itReusesTheSameInstanceWhenTheIdentifierIsTheFirstField(): void
    {
        $metadata = $this->metadataFactory->getMetadata(FakeTask::class);
        $context = new GraphqlExecutionContext();

        $first = $this->hydrator->hydrate($metadata, ['id' => 1, 'title' => 'A'], $context);
        $second = $this->hydrator->hydrate($metadata, ['id' => 1, 'title' => 'A'], $context);

        self::assertSame($first, $second);
    }

    #[Test]
    public function itReusesTheSameInstanceWhenTheIdentifierIsNotTheFirstField(): void
    {
        $metadata = $this->metadataFactory->getMetadata(FakeEntityWithIdentifierNotFirst::class);
        $context = new GraphqlExecutionContext();

        $first = $this->hydrator->hydrate($metadata, ['name' => 'A', 'id' => 1], $context);
        $second = $this->hydrator->hydrate($metadata, ['name' => 'A', 'id' => 1], $context);

        self::assertSame($first, $second);
    }

    #[Test]
    public function itCompletesAPartialEntityWhenItAppearsAgainWithMoreFields(): void
    {
        $metadata = $this->metadataFactory->getMetadata(FakeTask::class);
        $context = new GraphqlExecutionContext();

        $partial = $this->hydrator->hydrate($metadata, ['id' => 2], $context);
        $complete = $this->hydrator->hydrate($metadata, ['id' => 2, 'title' => 'B'], $context);

        self::assertSame($partial, $complete);
        self::assertInstanceOf(FakeTask::class, $complete);
        self::assertSame('B', $complete->title);
        self::assertSame(1, $context->trace->hydratedEntities);
    }

    #[Test]
    public function itRunsTheAfterHydrateHookOnceAPartialEntityIsComplete(): void
    {
        $metadata = $this->metadataFactory->getMetadata(FakeEntityWithAfterHydrate::class);
        $context = new GraphqlExecutionContext();

        $this->hydrator->hydrate($metadata, ['id' => 1], $context);
        $product = $this->hydrator->hydrate($metadata, ['id' => 1, 'price' => 100.0, 'taxRate' => 20.0], $context);
        $this->hydrator->hydrate($metadata, ['id' => 1, 'price' => 100.0, 'taxRate' => 20.0], $context);

        self::assertInstanceOf(FakeEntityWithAfterHydrate::class, $product);
        self::assertSame(120.0, $product->priceWithTax);
        self::assertSame(1, $product->afterHydrateCallCount);
    }

    #[Test]
    public function itCreatesDistinctInstancesForDistinctIdentifiers(): void
    {
        $metadata = $this->metadataFactory->getMetadata(FakeEntityWithIdentifierNotFirst::class);
        $context = new GraphqlExecutionContext();

        $first = $this->hydrator->hydrate($metadata, ['name' => 'A', 'id' => 1], $context);
        $second = $this->hydrator->hydrate($metadata, ['name' => 'B', 'id' => 2], $context);

        self::assertNotSame($first, $second);
    }
}
