<?php

declare(strict_types=1);

namespace GraphqlOrm\Execution;

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
     * @var (\Closure(class-string, list<int|string>): list<object>)|null
     */
    public ?\Closure $lazyLoader = null;

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
