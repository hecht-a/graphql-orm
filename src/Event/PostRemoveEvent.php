<?php

declare(strict_types=1);

namespace GraphqlOrm\Event;

/**
 * Dispatched once flush() deleted the entity.
 */
final readonly class PostRemoveEvent extends LifecycleEvent
{
}
