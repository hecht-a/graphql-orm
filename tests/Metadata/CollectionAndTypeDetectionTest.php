<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Metadata;

use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Tests\Fixtures\Docblock\Author;
use GraphqlOrm\Tests\Fixtures\Docblock\Book;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeUser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CollectionAndTypeDetectionTest extends TestCase
{
    private GraphqlEntityMetadataFactory $metadataFactory;

    protected function setUp(): void
    {
        $this->metadataFactory = new GraphqlEntityMetadataFactory();
    }

    #[Test]
    public function itDetectsACollectionFromAnArrayDocblockInTheSameNamespace(): void
    {
        $field = $this->field('books');

        self::assertSame(Book::class, $field->relation);
        self::assertTrue($field->isCollection);
    }

    #[Test]
    public function itDetectsACollectionFromAListDocblockResolvingTheUseStatement(): void
    {
        $field = $this->field('tasks');

        self::assertSame(FakeTask::class, $field->relation);
        self::assertTrue($field->isCollection);
    }

    #[Test]
    public function itKeepsAnArrayOfScalarsAsAPlainField(): void
    {
        $field = $this->field('tags');

        self::assertNull($field->relation);
        self::assertFalse($field->isCollection);
    }

    #[Test]
    public function itKeepsAnArrayWithoutDocblockAsAPlainField(): void
    {
        $field = $this->field('raw');

        self::assertNull($field->relation);
        self::assertFalse($field->isCollection);
    }

    #[Test]
    public function itKeepsAnUntypedMappedProperty(): void
    {
        $field = $this->field('legacy');

        self::assertNull($field->relation);
    }

    #[Test]
    public function itAcceptsAnExplicitTargetEntityOnAUnionTypedProperty(): void
    {
        $field = $this->field('editor');

        self::assertSame(FakeUser::class, $field->relation);
        self::assertFalse($field->isCollection);
    }

    #[Test]
    public function itHydratesADocblockCollectionAndAnUntypedProperty(): void
    {
        $hydrator = new EntityHydrator($this->metadataFactory);
        $data = [
            'id' => 1,
            'books' => [['id' => 10, 'title' => 'Dune']],
            'legacy' => 'as is',
        ];

        $author = $hydrator->hydrate($this->metadataFactory->getMetadata(Author::class), $data, new GraphqlExecutionContext());

        self::assertInstanceOf(Author::class, $author);
        self::assertInstanceOf(Book::class, $author->books[0] ?? null);
        self::assertSame('Dune', $author->books[0]->title);
        self::assertSame('as is', $author->legacy);
    }

    private function field(string $property): GraphqlFieldMetadata
    {
        foreach ($this->metadataFactory->getMetadata(Author::class)->fields as $field) {
            if ($field->property === $property) {
                return $field;
            }
        }

        self::fail(\sprintf('Field "%s" is not mapped.', $property));
    }
}
