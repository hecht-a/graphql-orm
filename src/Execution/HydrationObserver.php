<?php

declare(strict_types=1);

namespace GraphqlOrm\Execution;

/**
 * Told of the entities the hydrator leaves to be loaded on first access, and of their loading: the profiler sums them
 * up.
 *
 * @internal
 */
interface HydrationObserver
{
    /**
     * An entity of which only the identifier is set, to be loaded on first access.
     *
     * @param string|null $relation the relation holding it, e.g. App\Entity\Task::$assignee
     */
    public function ghostCreated(string $class, int|string $identifier, ?string $relation): void;

    /**
     * Entities loaded on first access, by the query just recorded.
     *
     * @param list<int|string> $identifiers
     */
    public function ghostsLoaded(string $class, array $identifiers): void;
}
