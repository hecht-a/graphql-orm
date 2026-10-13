<?php

declare(strict_types=1);

namespace GraphqlOrm\Event;

/**
 * Dispatched before the query is sent: the trace holds the query and its variables, not yet the endpoint nor the stats.
 */
final readonly class QueryStartedEvent extends QueryEvent
{
}
