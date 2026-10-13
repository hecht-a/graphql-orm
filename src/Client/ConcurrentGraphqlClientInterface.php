<?php

declare(strict_types=1);

namespace GraphqlOrm\Client;

use GraphqlOrm\Execution\GraphqlExecutionContext;

/**
 * A client sending a query without waiting for its response, so that several queries are in flight at once
 * (GraphqlQuery::concurrently()). Other clients run such queries one after the other.
 */
interface ConcurrentGraphqlClientInterface extends GraphqlClientInterface
{
    /**
     * Sends the query at once and returns a function waiting for its response.
     *
     * @param array<string, mixed> $variables
     *
     * @return \Closure(): array<string, mixed> throws as query() does
     *
     * @throws \GraphqlOrm\Exception\GraphqlRequestException when the request cannot be sent
     */
    public function send(string $query, GraphqlExecutionContext $context, array $variables = []): \Closure;
}
