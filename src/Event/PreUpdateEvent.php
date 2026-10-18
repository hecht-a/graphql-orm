<?php

declare(strict_types=1);

namespace GraphqlOrm\Event;

use GraphqlOrm\GraphqlManagerInterface;

/**
 * Dispatched by flush() before updating an entity changed since a query read it: what a listener sets is written.
 */
final readonly class PreUpdateEvent extends LifecycleEvent
{
    /**
     * @param list<string> $changedProperties the properties changed since a query read the entity
     */
    public function __construct(
        object $entity,
        GraphqlManagerInterface $manager,
        public array $changedProperties = [],
    ) {
        parent::__construct($entity, $manager);
    }
}
