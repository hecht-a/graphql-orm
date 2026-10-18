<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Persistence;

use GraphqlOrm\Cache\GraphqlCache;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Event\LifecycleEvent;
use GraphqlOrm\Event\PostFlushEvent;
use GraphqlOrm\Event\PostPersistEvent;
use GraphqlOrm\Event\PostRemoveEvent;
use GraphqlOrm\Event\PostUpdateEvent;
use GraphqlOrm\Event\PreFlushEvent;
use GraphqlOrm\Event\PrePersistEvent;
use GraphqlOrm\Event\PreRemoveEvent;
use GraphqlOrm\Event\PreUpdateEvent;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Execution\IdentityMap;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Tests\Fixtures\FakeEventDispatcher;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Fixtures\Write\Note;
use GraphqlOrm\Tests\Fixtures\Write\StampedNote;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The lifecycle of the entities written, as Doctrine: the callbacks of the entities and the events of the dispatcher.
 */
final class LifecycleEventsTest extends TestCase
{
    private const NOTE = ['id' => 3, 'title' => 'Note A', 'done' => false, 'due_date' => null, 'priority' => null, 'author' => null, 'cost_amount' => null, 'cost_currency' => null];

    #[Test]
    public function itDispatchesTheEventsOfTheEntitiesWritten(): void
    {
        $events = new FakeEventDispatcher();
        $client = new FakeGraphqlClient(
            ['data' => ['notes' => ['items' => [self::NOTE, ['id' => 4] + self::NOTE]]]],
            ['data' => ['m0' => ['id' => 12], 'm1' => ['id' => 3], 'm2' => ['id' => 4]]],
        );
        $manager = $this->manager($client, $events);
        [$changed, $deleted] = $manager->getRepository(Note::class)->findAll();
        $created = new Note();
        $created->title = 'Note C';

        $manager->persist($created);
        $manager->remove($deleted);
        $changed->done = true;
        $changed->title = 'Note B';
        $manager->flush();

        $lifecycle = array_values(array_filter($events->events, static fn (object $event): bool => !$event instanceof \GraphqlOrm\Event\QueryEvent));
        self::assertSame(
            [
                [PrePersistEvent::class, $created],
                [PreRemoveEvent::class, $deleted],
                [PreFlushEvent::class, null],
                [PreUpdateEvent::class, $changed],
                [PostPersistEvent::class, $created],
                [PostUpdateEvent::class, $changed],
                [PostRemoveEvent::class, $deleted],
                [PostFlushEvent::class, null],
            ],
            array_map(static fn (object $event): array => [$event::class, $event instanceof LifecycleEvent ? $event->entity : null], $lifecycle),
        );
        $preUpdate = $lifecycle[3];
        self::assertInstanceOf(PreUpdateEvent::class, $preUpdate);
        self::assertSame(['title', 'done'], $preUpdate->changedProperties);
        self::assertSame($manager, $preUpdate->manager);
    }

    #[Test]
    public function itWritesWhatTheCallbacksOfTheEntityChange(): void
    {
        $client = new FakeGraphqlClient(['data' => ['m0' => ['id' => 12]]], ['data' => ['m0' => ['id' => 12]]], ['data' => ['m0' => ['id' => 12]]]);
        $manager = $this->manager($client);
        $note = new StampedNote();
        $note->title = 'Note A';

        $manager->persist($note);
        $manager->flush();
        self::assertSame('mutation { m0: createNote(item: { title: "Note A", stamp: "created" }) { id } }', self::flatten($client->lastQuery));

        $note->title = 'Note B';
        $manager->flush();
        self::assertSame('mutation { m0: updateNote(id: 12, item: { title: "Note B", stamp: "updated" }) { id } }', self::flatten($client->lastQuery));

        $manager->remove($note);
        $manager->flush();

        self::assertSame(['prePersist', 'postPersist', 'preUpdate', 'postUpdate', 'preRemove', 'postRemove'], $note->callbacks);
    }

    #[Test]
    public function itWritesWhatAListenerChangesBeforeTheUpdate(): void
    {
        $client = new FakeGraphqlClient(['data' => ['notes' => ['items' => [self::NOTE]]]], ['data' => ['m0' => ['id' => 3]]]);
        $listener = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof PreUpdateEvent && $event->entity instanceof Note) {
                    $event->entity->done = true;
                }

                return $event;
            }
        };
        $manager = $this->manager($client, $listener);
        $note = $manager->getRepository(Note::class)->find(3);
        self::assertInstanceOf(Note::class, $note);

        $note->title = 'Note B';
        $manager->flush();

        self::assertSame('mutation { m0: updateNote(id: 3, item: { title: "Note B", done: true }) { id } }', self::flatten($client->lastQuery));
    }

    #[Test]
    public function itEvictsAnEntityUpdatedOrDeletedFromTheSecondLevelCache(): void
    {
        $cache = new GraphqlCache(entities: new ArrayAdapter());
        $client = new FakeGraphqlClient(
            ['data' => ['notes' => ['items' => [['id' => 3, 'title' => 'Note A', 'stamp' => null]]]]],
            ['data' => ['m0' => ['id' => 3]]],
            ['data' => ['notes' => ['items' => [['id' => 3, 'title' => 'Note A', 'stamp' => null]]]]],
            ['data' => ['m0' => ['id' => 3]]],
        );
        $manager = $this->manager($client, cache: $cache);
        $note = $manager->getRepository(StampedNote::class)->findBy(['id' => 3])[0];
        self::assertTrue($cache->containsEntity(StampedNote::class, 3));

        $note->title = 'Note B';
        $manager->flush();
        self::assertFalse($cache->containsEntity(StampedNote::class, 3));

        $manager->clear();
        $note = $manager->getRepository(StampedNote::class)->findBy(['id' => 3])[0];
        self::assertTrue($cache->containsEntity(StampedNote::class, 3));

        $manager->remove($note);
        $manager->flush();
        self::assertFalse($cache->containsEntity(StampedNote::class, 3));
    }

    #[Test]
    public function itRefusesAFlushFromAListenerOfTheFlush(): void
    {
        $client = new FakeGraphqlClient(['data' => ['m0' => ['id' => 12]]]);
        $listener = new class implements EventDispatcherInterface {
            public ?GraphqlManager $manager = null;

            public function dispatch(object $event): object
            {
                if ($event instanceof PostPersistEvent) {
                    $this->manager?->flush();
                }

                return $event;
            }
        };
        $manager = $this->manager($client, $listener);
        $listener->manager = $manager;
        $note = new Note();
        $note->title = 'Note A';
        $manager->persist($note);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('flush() was called while the entities were being written: call it once the flush is done, e.g. from a PostFlushEvent listener.');

        $manager->flush();
    }

    private static function flatten(string $graphql): string
    {
        return (string) preg_replace('/\s+/', ' ', $graphql);
    }

    private function manager(FakeGraphqlClient $client, ?EventDispatcherInterface $events = null, GraphqlCache $cache = new GraphqlCache()): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 1, new DataApiBuilderDialect(), eventDispatcher: $events, identityMap: new IdentityMap(), cache: $cache);
    }
}
