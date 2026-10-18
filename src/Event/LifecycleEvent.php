<?php

declare(strict_types=1);

namespace GraphqlOrm\Event;

use GraphqlOrm\GraphqlManagerInterface;

/**
 * Base of the events of an entity written by flush(), as the lifecycle events of Doctrine: listen to each of them, they
 * are dispatched under their own class name.
 */
abstract readonly class LifecycleEvent
{
    public function __construct(
        public object $entity,
        public GraphqlManagerInterface $manager,
    ) {
    }
}
