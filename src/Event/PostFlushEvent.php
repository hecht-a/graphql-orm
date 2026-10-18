<?php

declare(strict_types=1);

namespace GraphqlOrm\Event;

use GraphqlOrm\GraphqlManagerInterface;

/**
 * Dispatched once flush() wrote every entity, even when there was none.
 */
final readonly class PostFlushEvent
{
    public function __construct(
        public GraphqlManagerInterface $manager,
    ) {
    }
}
