<?php

declare(strict_types=1);

namespace GraphqlOrm\Pagination\KnpPaginator;

use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Pagination\OffsetPages;
use GraphqlOrm\Query\GraphqlQueryBuilder;
use Knp\Component\Pager\Event\ItemsEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Lets KnpPaginator paginate a query builder: `$paginator->paginate($queryBuilder, $page, 10)` counts the entities its
 * filter matches, then reads the rows of the page by offset. Needs a dialect paginating with an offset and counting the
 * entities, as HasuraDialect.
 */
final class QueryBuilderSubscriber implements EventSubscriberInterface
{
    /**
     * @throws LogicException when the dialect of the query builder paginates with cursors, or cannot count
     */
    public function items(ItemsEvent $event): void
    {
        if (!$event->target instanceof GraphqlQueryBuilder) {
            return;
        }

        $pages = new OffsetPages($event->target);
        $event->count = $pages->count();
        $event->items = $pages->slice($event->getOffset(), $event->getLimit());
        $event->stopPropagation();
    }

    public static function getSubscribedEvents(): array
    {
        return ['knp_pager.items' => ['items', 0]];
    }
}
