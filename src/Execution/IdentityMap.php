<?php

declare(strict_types=1);

namespace GraphqlOrm\Execution;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The entities loaded during a request, shared by its queries: an entity is one instance, updated by each query
 * returning it, and find() serves an entity known in full without a query. Reset between requests and messages
 * (kernel.reset), so that no stale data outlives them.
 */
final class IdentityMap implements ResetInterface
{
    /** @var array<string, array<int|string, object>> */
    private array $entities = [];

    /** @var \WeakMap<object, true> the entities hydrated from a row holding every mapped field */
    private \WeakMap $complete;

    public function __construct()
    {
        $this->complete = new \WeakMap();
    }

    /**
     * @return array<string, array<int|string, object>> class => identifier => entity
     */
    public function entities(): array
    {
        return $this->entities;
    }

    /**
     * @param array<string, array<int|string, object>> $entities class => identifier => entity
     * @param iterable<object>                         $complete the entities hydrated in full
     */
    public function merge(array $entities, iterable $complete): void
    {
        foreach ($entities as $class => $byIdentifier) {
            foreach ($byIdentifier as $identifier => $entity) {
                $this->entities[$class][$identifier] ??= $entity;
            }
        }

        foreach ($complete as $entity) {
            $this->complete[$entity] = true;
        }
    }

    /**
     * The entity known in full with this identifier, if any.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function find(string $class, int|string $identifier): ?object
    {
        $entity = $this->entities[$class][$identifier] ?? null;

        return $entity instanceof $class && isset($this->complete[$entity]) ? $entity : null;
    }

    /**
     * Records an entity created by flush(), not known in full: find() still queries it.
     */
    public function add(object $entity, int|string $identifier): void
    {
        $this->entities[$entity::class][$identifier] ??= $entity;
    }

    /**
     * Forgets an entity deleted by flush().
     */
    public function remove(object $entity, int|string $identifier): void
    {
        if (($this->entities[$entity::class][$identifier] ?? null) === $entity) {
            unset($this->entities[$entity::class][$identifier], $this->complete[$entity]);
        }
    }

    public function reset(): void
    {
        $this->entities = [];
        $this->complete = new \WeakMap();
    }
}
