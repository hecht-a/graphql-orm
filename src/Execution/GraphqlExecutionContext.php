<?php

declare(strict_types=1);

namespace GraphqlOrm\Execution;

use GraphqlOrm\Cache\GraphqlCache;
use GraphqlOrm\Converter\TypeConverterRegistry;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Persistence\UnitOfWork;
use GraphqlOrm\Query\GraphqlQueryTrace;

final class GraphqlExecutionContext
{
    /**
     * Loads entities by their identifiers, set when lazy loading is enabled: relations reduced to their identifier are
     * then hydrated as lazy ghosts, loaded on first access.
     *
     * @internal set by GraphqlManager
     *
     * @var (\Closure(class-string, list<int|string>, string|null=): list<object>)|null the third argument is the relation
     *                                                                                  accessed, e.g. Task::$assignee
     */
    public ?\Closure $lazyLoader = null;

    /**
     * Whether every relation reduced to its identifier is a lazy ghost (lazy_loading), not only those fetched lazily.
     *
     * @internal set by GraphqlManager
     */
    public bool $lazyLoading = false;

    /**
     * Told of the entities left to be loaded on first access.
     *
     * @internal set by GraphqlManager when the profiler is enabled
     */
    public ?HydrationObserver $observer = null;

    /**
     * Keeps the entities marked #[GraphqlOrm\Cache] hydrated in full; null for rows read from a cache, which must not
     * prolong them.
     *
     * @internal set by GraphqlManager
     */
    public ?GraphqlCache $entityCache = null;

    /**
     * Converts the values of the response into the value objects of the properties.
     *
     * @internal set by GraphqlManager
     */
    public ?TypeConverterRegistry $typeConverters = null;

    /**
     * Told of the fields of the entities hydrated, as the API has them: flush() writes those changed since.
     *
     * @internal set by GraphqlManager
     */
    public ?UnitOfWork $unitOfWork = null;

    /**
     * The entities already kept in the second-level cache by this response, which may hold one several times.
     *
     * @internal filled by EntityHydrator
     *
     * @var array<string, true> by class and identifier
     */
    public array $keptInCache = [];

    /**
     * The entities hydrated from a row holding every mapped field, which a shared identity map may serve to find().
     *
     * @internal filled by EntityHydrator
     *
     * @var list<object>
     */
    public array $completeEntities = [];

    /**
     * The identity map of the manager, read for the entities this query does not know yet: it is not copied for each
     * query, as it may hold many entities late in a request.
     *
     * @internal set by GraphqlManager
     */
    public ?IdentityMap $sharedIdentityMap = null;

    /**
     * @param array<string, array<int|string, object>> $identityMap the entities hydrated by this query, merged into the
     *                                                              shared identity map once it succeeds
     */
    public function __construct(
        public readonly GraphqlQueryTrace $trace = new GraphqlQueryTrace(),
        public array $identityMap = [],
        public readonly GraphqlQueryDialect $dialect = new DefaultDialect(),
    ) {
    }

    /**
     * The entity of this identifier hydrated by this query, or known to the shared identity map.
     *
     * @internal read by EntityHydrator
     */
    public function knownEntity(string $class, int|string $identifier): ?object
    {
        return $this->identityMap[$class][$identifier] ?? $this->sharedIdentityMap?->get($class, $identifier);
    }

    /**
     * @internal read by EntityHydrator
     *
     * @return array<int|string, object> the entities of this class hydrated by this query or known to the shared
     *                                   identity map, by identifier
     */
    public function knownEntitiesOf(string $class): array
    {
        return ($this->identityMap[$class] ?? []) + ($this->sharedIdentityMap?->entitiesOf($class) ?? []);
    }
}
