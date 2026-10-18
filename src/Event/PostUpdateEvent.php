<?php

declare(strict_types=1);

namespace GraphqlOrm\Event;

/**
 * Dispatched once flush() updated the entity.
 */
final readonly class PostUpdateEvent extends LifecycleEvent
{
}
