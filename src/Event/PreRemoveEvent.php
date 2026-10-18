<?php

declare(strict_types=1);

namespace GraphqlOrm\Event;

/**
 * Dispatched by remove() on an entity a query read, before flush() deletes it.
 */
final readonly class PreRemoveEvent extends LifecycleEvent
{
}
