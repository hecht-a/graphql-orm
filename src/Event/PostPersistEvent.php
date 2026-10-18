<?php

declare(strict_types=1);

namespace GraphqlOrm\Event;

/**
 * Dispatched once flush() created the entity: its identifier is set.
 */
final readonly class PostPersistEvent extends LifecycleEvent
{
}
