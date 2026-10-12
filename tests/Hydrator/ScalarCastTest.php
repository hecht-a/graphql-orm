<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Hydrator;

use GraphqlOrm\Exception\CastException;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityWithScalars;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScalarCastTest extends TestCase
{
    private EntityHydrator $hydrator;

    private GraphqlEntityMetadataFactory $metadataFactory;

    protected function setUp(): void
    {
        $this->metadataFactory = new GraphqlEntityMetadataFactory();
        $this->hydrator = new EntityHydrator($this->metadataFactory);
    }

    #[Test]
    public function itCastsANumericStringToFloat(): void
    {
        // Arrange
        $data = ['price' => '1.5'];

        // Act
        $entity = $this->hydrate($data);

        // Assert
        self::assertSame(1.5, $entity->price);
    }

    #[Test]
    public function itThrowsACastExceptionWhenAFloatValueIsNotNumeric(): void
    {
        // Arrange
        $data = ['price' => 'abc'];

        // Act & Assert
        $this->expectException(CastException::class);
        $this->hydrate($data);
    }

    #[Test]
    public function itThrowsACastExceptionWhenABoolValueIsNotBooleanLike(): void
    {
        // Arrange
        $data = ['active' => 'maybe'];

        // Act & Assert
        $this->expectException(CastException::class);
        $this->hydrate($data);
    }

    #[Test]
    public function itThrowsACastExceptionWhenAStringValueIsNotScalar(): void
    {
        // Arrange
        $data = ['label' => ['not', 'a', 'string']];

        // Act & Assert
        $this->expectException(CastException::class);
        $this->hydrate($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrate(array $data): FakeEntityWithScalars
    {
        $entity = $this->hydrator->hydrate(
            $this->metadataFactory->getMetadata(FakeEntityWithScalars::class),
            $data,
            new GraphqlExecutionContext(),
        );

        self::assertInstanceOf(FakeEntityWithScalars::class, $entity);

        return $entity;
    }
}
