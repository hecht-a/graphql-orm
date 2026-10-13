<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Benchmark;

use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\Execution\GraphqlExecutionContext;

/**
 * Answers every query with the same response, so that a benchmark measures the bundle, not the network.
 */
final readonly class StaticGraphqlClient implements GraphqlClientInterface
{
    /**
     * @param array<string, mixed> $response
     */
    public function __construct(
        private array $response,
    ) {
    }

    public function query(string $query, GraphqlExecutionContext $context, array $variables = []): array
    {
        return $this->response;
    }
}
