<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Persistence;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Exception\FlushException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Execution\IdentityMap;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Tests\Fixtures\Dto\Priority;
use GraphqlOrm\Tests\Fixtures\Embedded\Money;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Fixtures\Write\Author;
use GraphqlOrm\Tests\Fixtures\Write\Category;
use GraphqlOrm\Tests\Fixtures\Write\Comment;
use GraphqlOrm\Tests\Fixtures\Write\Note;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * flush() writes the entities persisted, changed and removed since they were read, with one mutation document.
 */
final class FlushTest extends TestCase
{
    private const NOTE = ['id' => 3, 'title' => 'Note A', 'done' => false, 'due_date' => null, 'priority' => null, 'author' => ['id' => 1], 'cost_amount' => null, 'cost_currency' => null];

    #[Test]
    public function itCreatesAPersistedEntity(): void
    {
        $client = new FakeGraphqlClient(['data' => ['m0' => ['id' => 12]]]);
        $manager = $this->manager($client);
        $note = new Note();
        $note->title = 'Note A';
        $note->dueDate = new \DateTimeImmutable('2026-01-15T10:00:00+00:00');
        $note->priority = Priority::High;
        $note->cost = new Money(1500, 'EUR');

        $manager->persist($note);
        $manager->flush();

        self::assertSame(
            'mutation { m0: createNote(item: { title: "Note A", done: false, due_date: "2026-01-15T10:00:00+00:00", priority: "high", author_id: null, cost_amount: 1500, cost_currency: "EUR" }) { id } }',
            self::flatten($client->lastQuery),
        );
        self::assertSame(12, $note->id);
    }

    #[Test]
    public function itUpdatesTheFieldsChangedOnly(): void
    {
        $client = new FakeGraphqlClient(['data' => ['notes' => ['items' => [self::NOTE]]]], ['data' => ['m0' => ['id' => 3]]]);
        $manager = $this->manager($client);
        $note = $manager->getRepository(Note::class)->find(3);
        self::assertInstanceOf(Note::class, $note);

        $note->title = 'Note B';
        $note->cost = new Money(200, 'EUR');
        $manager->flush();

        self::assertSame(
            'mutation { m0: updateNote(id: 3, item: { title: "Note B", cost_amount: 200, cost_currency: "EUR" }) { id } }',
            self::flatten($client->lastQuery),
        );
    }

    #[Test]
    public function itSendsNothingWhenNothingChanged(): void
    {
        $client = new FakeGraphqlClient(['data' => ['notes' => ['items' => [self::NOTE]]]]);
        $manager = $this->manager($client);
        $note = $manager->getRepository(Note::class)->find(3);
        self::assertInstanceOf(Note::class, $note);

        $note->title = 'Note A';
        $manager->flush();

        self::assertSame(1, $client->callCount);
    }

    #[Test]
    public function itUpdatesAnEntityOnceItsChangesAreWritten(): void
    {
        $client = new FakeGraphqlClient(['data' => ['notes' => ['items' => [self::NOTE]]]], ['data' => ['m0' => ['id' => 3]]]);
        $manager = $this->manager($client);
        $note = $manager->getRepository(Note::class)->find(3);
        self::assertInstanceOf(Note::class, $note);

        $note->done = true;
        $manager->flush();
        $manager->flush();

        self::assertSame(2, $client->callCount);
    }

    #[Test]
    public function itNeverWritesAFieldTheQueryDidNotSelect(): void
    {
        $client = new FakeGraphqlClient(['data' => ['notes' => ['items' => [['id' => 3, 'title' => 'Note A']]]]], ['data' => ['m0' => ['id' => 3]]]);
        $manager = $this->manager($client);
        $notes = $manager->getRepository(Note::class)->createQueryBuilder()->select('title')->getQuery()->getResult();
        self::assertIsArray($notes);
        $note = $notes[0];
        self::assertInstanceOf(Note::class, $note);

        $note->title = 'Note B';
        $note->done = true;
        $manager->flush();

        self::assertSame('mutation { m0: updateNote(id: 3, item: { title: "Note B" }) { id } }', self::flatten($client->lastQuery));
    }

    #[Test]
    public function itDeletesARemovedEntity(): void
    {
        $client = new FakeGraphqlClient(['data' => ['notes' => ['items' => [self::NOTE]]]], ['data' => ['m0' => ['id' => 3]]]);
        $manager = $this->manager($client);
        $note = $manager->getRepository(Note::class)->find(3);
        self::assertInstanceOf(Note::class, $note);

        $note->title = 'Note B';
        $manager->remove($note);
        $manager->flush();

        self::assertSame('mutation { m0: deleteNote(id: 3) { id } }', self::flatten($client->lastQuery));
    }

    #[Test]
    public function itForgetsAnEntityRemovedBeforeBeingWritten(): void
    {
        $client = new FakeGraphqlClient();
        $manager = $this->manager($client);
        $note = new Note();
        $note->title = 'Note A';

        $manager->persist($note);
        $manager->remove($note);
        $manager->flush();

        self::assertSame(0, $client->callCount);
    }

    #[Test]
    public function itWritesTheChangesOfSeveralEntitiesWithOneDocument(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['notes' => ['items' => [self::NOTE, ['id' => 4] + self::NOTE]]]],
            ['data' => ['m0' => ['id' => 13], 'm1' => ['id' => 3], 'm2' => ['id' => 4]]],
        );
        $manager = $this->manager($client);
        [$first, $second] = $manager->getRepository(Note::class)->findAll();
        $note = new Note();
        $note->title = 'Note C';

        $first->done = true;
        $manager->remove($second);
        $manager->persist($note);
        $manager->flush();

        self::assertSame(
            'mutation { m0: createNote(item: { title: "Note C", done: false, due_date: null, priority: null, author_id: null, cost_amount: null, cost_currency: null }) { id } m1: updateNote(id: 3, item: { done: true }) { id } m2: deleteNote(id: 4) { id } }',
            self::flatten($client->lastQuery),
        );
        self::assertSame(2, $client->callCount);
    }

    #[Test]
    public function itWritesARelationThroughItsJoinField(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['notes' => ['items' => [self::NOTE]]]],
            ['data' => ['authors' => ['items' => [['id' => 2, 'name' => 'User B']]]]],
            ['data' => ['m0' => ['id' => 3]]],
        );
        $manager = $this->manager($client);
        $note = $manager->getRepository(Note::class)->find(3);
        self::assertInstanceOf(Note::class, $note);

        $note->author = $manager->getRepository(Author::class)->find(2);
        $manager->flush();

        self::assertSame('mutation { m0: updateNote(id: 3, item: { author_id: 2 }) { id } }', self::flatten($client->lastQuery));
    }

    #[Test]
    public function itCreatesTheRelatedEntityFirst(): void
    {
        $client = new FakeGraphqlClient(['data' => ['m0' => ['id' => 5]]], ['data' => ['m0' => ['id' => 12]]]);
        $manager = $this->manager($client);
        $author = new Author();
        $author->name = 'User A';
        $note = new Note();
        $note->title = 'Note A';
        $note->author = $author;

        $manager->persist($note);
        $manager->persist($author);
        $manager->flush();

        self::assertSame(
            [
                'mutation { m0: createAuthor(item: { name: "User A" }) { id } }',
                'mutation { m0: createNote(item: { title: "Note A", done: false, due_date: null, priority: null, author_id: 5, cost_amount: null, cost_currency: null }) { id } }',
            ],
            array_map(self::flatten(...), $client->queries),
        );
        self::assertSame(5, $author->id);
        self::assertSame(12, $note->id);
    }

    #[Test]
    public function itRefusesNewEntitiesNeedingTheIdentifiersOfEachOther(): void
    {
        $manager = $this->manager(new FakeGraphqlClient());
        $first = new Category();
        $first->name = 'Category A';
        $second = new Category();
        $second->name = 'Category B';
        $first->related = $second;
        $second->related = $first;

        $manager->persist($first);
        $manager->persist($second);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The new entities "GraphqlOrm\Tests\Fixtures\Write\Category" and those it relates to need the identifiers of each other: flush() one of them first.');

        $manager->flush();
    }

    #[Test]
    public function itRefusesARelatedEntityNotPersisted(): void
    {
        $manager = $this->manager(new FakeGraphqlClient());
        $note = new Note();
        $note->title = 'Note A';
        $note->author = new Author();

        $manager->persist($note);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"GraphqlOrm\Tests\Fixtures\Write\Note::$author" holds a new "GraphqlOrm\Tests\Fixtures\Write\Author" that was not persisted: call persist() on it as well.');

        $manager->flush();
    }

    #[Test]
    public function itRefusesARelationWithoutJoinField(): void
    {
        $author = new Author();
        $author->id = 1;
        $comment = new Comment();
        $comment->body = 'Comment A';
        $comment->author = $author;
        $manager = $this->manager(new FakeGraphqlClient());

        $manager->persist($comment);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"GraphqlOrm\Tests\Fixtures\Write\Comment::$author" has no join field to be written: name the field holding the identifier of the related entity with #[GraphqlOrm\Relation(joinField: \'author_id\')].');

        $manager->flush();
    }

    #[Test]
    public function itTellsWhatWasWrittenWhenAMutationFails(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['m0' => ['id' => 12], 'm1' => null], 'errors' => [['message' => 'The title is too long.', 'path' => ['m1']]]],
            ['data' => ['m0' => ['id' => 13]]],
        );
        $manager = $this->manager($client);
        $first = new Note();
        $first->title = 'Note A';
        $second = new Note();
        $second->title = str_repeat('a', 300);
        $manager->persist($first);
        $manager->persist($second);

        try {
            $manager->flush();
            self::fail('The flush should fail.');
        } catch (FlushException $exception) {
            self::assertSame(
                "1 of the 2 mutations were applied. Failed:\n- createNote (GraphqlOrm\\Tests\\Fixtures\\Write\\Note): The title is too long.",
                $exception->getMessage(),
            );
        }

        $second->title = 'Note B';
        $manager->flush();

        self::assertSame(12, $first->id);
        self::assertSame(13, $second->id);
        self::assertStringContainsString('createNote(item: { title: "Note B"', $client->lastQuery);
        self::assertSame(2, $client->callCount);
    }

    #[Test]
    public function itRefusesADialectThatCannotWrite(): void
    {
        $manager = $this->manager(new FakeGraphqlClient(), new DefaultDialect());
        $note = new Note();
        $note->title = 'Note A';
        $manager->persist($note);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"GraphqlOrm\Dialect\DefaultDialect" cannot write the entities: flush() needs a dialect implementing GraphqlOrm\Dialect\MutationDialect.');

        $manager->flush();
    }

    #[Test]
    public function itForgetsTheEntitiesOfAClearedManager(): void
    {
        $client = new FakeGraphqlClient();
        $manager = $this->manager($client);
        $note = new Note();
        $note->title = 'Note A';

        $manager->persist($note);
        $manager->clear();
        $manager->flush();

        self::assertSame(0, $client->callCount);
    }

    #[Test]
    public function itRefusesToWriteWithAReadOnlyManager(): void
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $client = new FakeGraphqlClient(['data' => ['notes' => ['items' => [self::NOTE]]]]);
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 1, new DataApiBuilderDialect(), identityMap: new IdentityMap(), readOnly: true, name: 'crm');
        $note = $manager->getRepository(Note::class)->find(3);
        self::assertInstanceOf(Note::class, $note);
        $message = 'The GraphQL ORM manager "crm" is read-only (read_only: true): it does not write the entities.';

        foreach ([static fn () => $manager->persist(new Note()), static fn () => $manager->remove($note), $manager->flush(...)] as $write) {
            try {
                $write();
                self::fail('A read-only manager should refuse to write.');
            } catch (LogicException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }

        self::assertSame(1, $client->callCount);
    }

    private static function flatten(string $graphql): string
    {
        return (string) preg_replace('/\s+/', ' ', $graphql);
    }

    private function manager(FakeGraphqlClient $client, GraphqlQueryDialect $dialect = new DataApiBuilderDialect()): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 1, $dialect, identityMap: new IdentityMap());
    }
}
