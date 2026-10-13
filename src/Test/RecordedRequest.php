<?php

declare(strict_types=1);

namespace GraphqlOrm\Test;

/**
 * A GraphQL request received by the MockGraphqlServer.
 */
final readonly class RecordedRequest
{
    /**
     * @param array<mixed> $variables
     */
    public function __construct(
        public string $query,
        public array $variables,
    ) {
    }
}
