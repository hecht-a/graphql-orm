<?php

declare(strict_types=1);

namespace GraphqlOrm\Event;

/**
 * Dispatched once the response is hydrated: the trace holds the duration, the response size and the hydration stats.
 */
final readonly class QueryExecutedEvent extends QueryEvent
{
}
