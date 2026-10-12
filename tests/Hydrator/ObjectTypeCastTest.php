<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Hydrator;

use GraphqlOrm\Exception\CastException;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityWithObjectTypes;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeStatus;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ObjectTypeCastTest extends TestCase
{
    private EntityHydrator $hydrator;

    private GraphqlEntityMetadataFactory $metadataFactory;

    protected function setUp(): void
    {
        $this->metadataFactory = new GraphqlEntityMetadataFactory();
        $this->hydrator = new EntityHydrator($this->metadataFactory);
    }

    #[Test]
    public function itCastsAValueToABackedEnum(): void
    {
        $entity = $this->hydrate(['status' => 'CLOSED']);

        self::assertSame(FakeStatus::Closed, $entity->status);
    }

    #[Test]
    public function itThrowsACastExceptionForAnUnknownEnumValue(): void
    {
        $this->expectException(CastException::class);
        $this->hydrate(['status' => 'ARCHIVED']);
    }

    #[Test]
    public function itCastsAStringToAMutableDateTime(): void
    {
        $entity = $this->hydrate(['createdAt' => '2026-01-15T10:00:00+00:00']);

        self::assertInstanceOf(\DateTime::class, $entity->createdAt);
        self::assertSame('2026-01-15', $entity->createdAt->format('Y-m-d'));
    }

    #[Test]
    public function itCastsAStringToADateTimeInterface(): void
    {
        $entity = $this->hydrate(['updatedAt' => '2026-01-15T10:00:00+00:00']);

        self::assertInstanceOf(\DateTimeImmutable::class, $entity->updatedAt);
    }

    #[Test]
    public function itThrowsACastExceptionForAnInvalidDate(): void
    {
        $this->expectException(CastException::class);
        $this->hydrate(['createdAt' => 'not a date']);
    }

    #[Test]
    public function itCountsAnEntityReusedFromTheIdentityMapOnlyOnce(): void
    {
        $metadata = $this->metadataFactory->getMetadata(FakeTask::class);
        $context = new GraphqlExecutionContext();

        $this->hydrator->hydrate($metadata, ['id' => 1, 'title' => 'A'], $context);
        $this->hydrator->hydrate($metadata, ['id' => 1, 'title' => 'A'], $context);

        self::assertSame(1, $context->trace->hydratedEntities);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrate(array $data): FakeEntityWithObjectTypes
    {
        $entity = $this->hydrator->hydrate(
            $this->metadataFactory->getMetadata(FakeEntityWithObjectTypes::class),
            ['id' => 1, ...$data],
            new GraphqlExecutionContext(),
        );

        self::assertInstanceOf(FakeEntityWithObjectTypes::class, $entity);

        return $entity;
    }
}
