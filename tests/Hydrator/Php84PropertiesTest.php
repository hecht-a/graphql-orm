<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Hydrator;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Execution\IdentityMap;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Fixtures\Php84\Comment;
use GraphqlOrm\Tests\Fixtures\Php84\Note;
use GraphqlOrm\Tests\Fixtures\Php84\NoteWithVirtualProperty;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The entities restore the state the API has, whatever the hooks, the readonly and the visibility of their properties.
 */
final class Php84PropertiesTest extends TestCase
{
    private const NOTE = ['id' => 1, 'title' => 'Note A', 'body' => '  Body A  ', 'code' => 'abc'];

    #[Test]
    public function itHydratesAReadonlyPropertyOnceWhenAnotherQueryReturnsTheEntity(): void
    {
        $notes = $this->manager(new FakeGraphqlClient(self::response(), self::response()))->getRepository(Note::class);

        $note = $notes->findAll()[0];

        self::assertSame($note, $notes->findAll()[0]);
        self::assertSame(1, $note->id);
    }

    #[Test]
    public function itHydratesWithoutTheSetHooks(): void
    {
        $note = $this->manager(new FakeGraphqlClient(self::response()))->getRepository(Note::class)->findAll()[0];

        self::assertSame(0, $note->setHookCalls);
        self::assertSame('  Body A  ', (new \ReflectionProperty($note, 'body'))->getRawValue($note));
    }

    #[Test]
    public function itWritesTheValuesTheEntityHoldsRatherThanThoseOfItsGetHooks(): void
    {
        $client = new FakeGraphqlClient(['data' => ['m0' => ['id' => 7]]]);
        $manager = $this->manager($client);
        $note = new Note();
        $note->rename('Note A');
        $note->body = 'Body A';
        $note->code = 'abc';

        $manager->persist($note);
        $manager->flush();

        self::assertSame('mutation { m0: createNote(item: { title: "Note A", body: "Body A", code: "abc" }) { id } }', (string) preg_replace('/\s+/', ' ', $client->lastQuery));
        self::assertSame(7, $note->id);
    }

    #[Test]
    public function itWritesAPropertyOnlyTheEntityCanChange(): void
    {
        $client = new FakeGraphqlClient(self::response(), ['data' => ['m0' => ['id' => 1]]]);
        $manager = $this->manager($client);
        $note = $manager->getRepository(Note::class)->findAll()[0];

        $note->rename('Note B');
        $manager->flush();

        self::assertSame('mutation { m0: updateNote(id: 1, item: { title: "Note B" }) { id } }', (string) preg_replace('/\s+/', ' ', $client->lastQuery));
    }

    #[Test]
    public function itLoadsALazyEntityWithoutItsHooksNorWritingItsReadonlyIdentifierAgain(): void
    {
        $client = new FakeGraphqlClient(['data' => ['comments' => ['items' => [['id' => 5, 'note' => ['id' => 1]]]]]], self::response());
        $comment = $this->manager($client)->getRepository(Comment::class)->findAll()[0];

        self::assertNotNull($comment->note);
        self::assertSame('Note A', $comment->note->title);
        self::assertSame(1, $comment->note->id);
        self::assertSame(0, $comment->note->setHookCalls);
        self::assertSame(2, $client->callCount);
    }

    #[Test]
    public function itRefusesToMapAPropertyHoldingNoValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"GraphqlOrm\Tests\Fixtures\Php84\NoteWithVirtualProperty::$slug" is a virtual property');

        (new GraphqlEntityMetadataFactory())->getMetadata(NoteWithVirtualProperty::class);
    }

    /**
     * @return array<string, mixed>
     */
    private static function response(): array
    {
        return ['data' => ['notes' => ['items' => [self::NOTE]]]];
    }

    private function manager(FakeGraphqlClient $client): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 1, new DataApiBuilderDialect(), identityMap: new IdentityMap());
    }
}
