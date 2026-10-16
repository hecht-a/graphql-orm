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

    /**
     * An entity hydrated from a selection of its fields, by the query being sent.
     *
     * @param list<string> $unselected the mapped properties the query did not select
     */
    public function partialEntityHydrated(string $class, array $unselected): void;

    /**
     * A property of a partial entity read without being selected: a PartialEntityException is about to be thrown.
     *
     * @param list<string> $missing the properties not selected and left without a value
     */
    public function partialEntityRead(string $class, array $missing): void;
}
