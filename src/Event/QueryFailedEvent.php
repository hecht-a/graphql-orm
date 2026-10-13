<?php

declare(strict_types=1);

namespace GraphqlOrm\Event;

use GraphqlOrm\Query\GraphqlQueryTrace;

/**
 * Dispatched when sending the query or hydrating its response fails; the exception is rethrown afterwards.
 */
final readonly class QueryFailedEvent extends QueryEvent
{
    public function __construct(
        GraphqlQueryTrace $trace,
        public \Throwable $exception,
    ) {
        parent::__construct($trace);
    }
}
