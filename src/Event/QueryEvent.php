<?php

declare(strict_types=1);

namespace GraphqlOrm\Event;

use GraphqlOrm\Query\GraphqlQueryTrace;

/**
 * Base of the query events: listen to it to receive all of them.
 *
 * The trace is a copy, with query values masked when `redact_query_values` is enabled, so that a listener exporting it
 * (metrics, tracing) does not leak personal data. Its `id` is shared by the events of a same query.
 */
abstract readonly class QueryEvent
{
    public function __construct(
        public GraphqlQueryTrace $trace,
    ) {
    }
}
