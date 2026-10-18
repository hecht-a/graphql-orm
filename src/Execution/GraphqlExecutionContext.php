<?php

declare(strict_types=1);

namespace GraphqlOrm\Execution;

use GraphqlOrm\Cache\GraphqlCache;
use GraphqlOrm\Converter\TypeConverterRegistry;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
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
     * @param array<string, array<int|string, object>> $identityMap
     */
    public function __construct(
        public readonly GraphqlQueryTrace $trace = new GraphqlQueryTrace(),
        public array $identityMap = [],
        public readonly GraphqlQueryDialect $dialect = new DefaultDialect(),
    ) {
    }
}
