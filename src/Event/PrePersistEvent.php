<?php

declare(strict_types=1);

namespace GraphqlOrm\Event;

/**
 * Dispatched by persist() on a new entity, before flush() creates it: what a listener sets is written.
 */
final readonly class PrePersistEvent extends LifecycleEvent
{
}
