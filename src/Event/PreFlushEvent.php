<?php

declare(strict_types=1);

namespace GraphqlOrm\Event;

use GraphqlOrm\GraphqlManagerInterface;

/**
 * Dispatched when flush() starts, before it looks for the entities to write: what a listener changes is written.
 */
final readonly class PreFlushEvent
{
    public function __construct(
        public GraphqlManagerInterface $manager,
    ) {
    }
}
