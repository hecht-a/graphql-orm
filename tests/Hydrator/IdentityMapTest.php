<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Hydrator;

use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
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
    public function itCreatesDistinctInstancesForDistinctIdentifiers(): void
    {
        $metadata = $this->metadataFactory->getMetadata(FakeEntityWithIdentifierNotFirst::class);
        $context = new GraphqlExecutionContext();

        $first = $this->hydrator->hydrate($metadata, ['name' => 'A', 'id' => 1], $context);
        $second = $this->hydrator->hydrate($metadata, ['name' => 'B', 'id' => 2], $context);

        self::assertNotSame($first, $second);
    }
}
