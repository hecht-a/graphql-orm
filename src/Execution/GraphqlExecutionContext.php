<?php

declare(strict_types=1);

namespace GraphqlOrm\Execution;

use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Query\GraphqlQueryTrace;

final class GraphqlExecutionContext
{
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
