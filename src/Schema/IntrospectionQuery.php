<?php

declare(strict_types=1);

namespace GraphqlOrm\Schema;

use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Exception\GraphqlRequestException;
use GraphqlOrm\Execution\GraphqlExecutionContext;

/**
 * Sends an introspection query of the bundle, which goes to the client without the manager, and records it in the
 * profiler as the manager records the other queries.
 *
 * @internal
 */
final class IntrospectionQuery
{
    public const ORIGIN = 'introspection';

    /**
     * @return array<string, mixed> the response
     *
     * @throws GraphqlRequestException when the request fails or returns errors without data
     */
    public static function send(GraphqlClientInterface $client, string $graphql, ?GraphqlOrmDataCollector $collector): array
    {
        $context = new GraphqlExecutionContext();
        $context->trace->graphql = $graphql;
        $context->trace->startedAt = microtime(true);
        $context->trace->origin = self::ORIGIN;

        try {
            return $client->query($graphql, $context);
        } finally {
            $collector?->addQuery($context->trace);
        }
    }
}
