<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Pagination;

use GraphqlOrm\Dialect\HasuraDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Pagination\KnpPaginator\QueryBuilderSubscriber;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use Knp\Component\Pager\ArgumentAccess\ArgumentAccessInterface;
use Knp\Component\Pager\Event\ItemsEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * KnpPaginator paginates a query builder: its count, then the rows of the page by offset.
 */
final class KnpPaginatorSubscriberTest extends TestCase
{
    #[Test]
    public function itPaginatesAQueryBuilder(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['tasks_aggregate' => ['aggregate' => ['count' => 23]]]],
            ['data' => ['tasks' => [['id' => 21, 'title' => 'Task U'], ['id' => 22, 'title' => 'Task V']]]],
        );
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 1, new HasuraDialect());
        $event = self::event(20, 10);
        $event->target = (new GraphqlEntityRepository($manager, FakeTask::class))->createQueryBuilder();

        (new QueryBuilderSubscriber())->items($event);

        self::assertSame(23, $event->count);
        self::assertSame([21, 22], self::ids($event->items));
        self::assertTrue($event->isPropagationStopped());
    }

    #[Test]
    public function itLeavesTheOtherTargetsToTheOtherSubscribers(): void
    {
        $event = self::event(0, 10);
        $event->target = ['Task A'];

        (new QueryBuilderSubscriber())->items($event);

        self::assertNull($event->items);
        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function itListensToTheItemsOfKnpPaginator(): void
    {
        self::assertArrayHasKey('knp_pager.items', QueryBuilderSubscriber::getSubscribedEvents());
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $tasks): array
    {
        self::assertIsArray($tasks);

        return array_values(array_map(static fn (mixed $task): int => $task instanceof FakeTask ? $task->id : 0, $tasks));
    }

    private static function event(int $offset, int $limit): ItemsEvent
    {
        return new ItemsEvent($offset, $limit, new class implements ArgumentAccessInterface {
            public function has(string $name): bool
            {
                return false;
            }

            public function get(string $name): string|int|float|bool|null
            {
                return null;
            }

            public function set(string $name, string|int|float|bool|null $value): void
            {
            }
        });
    }
}
