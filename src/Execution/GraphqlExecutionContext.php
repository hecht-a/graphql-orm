<?php

declare(strict_types=1);

namespace GraphqlOrm\Execution;

use GraphqlOrm\Query\GraphqlQueryTrace;

final class GraphqlExecutionContext
{
    /**
     * @param array<string, array<string, object>> $identityMap
     */
    public function __construct(
        public readonly GraphqlQueryTrace $trace = new GraphqlQueryTrace(),
        public array $identityMap = [],
    ) {
    }
}
