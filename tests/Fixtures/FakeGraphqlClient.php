<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures;

use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\Execution\GraphqlExecutionContext;

final class FakeGraphqlClient implements GraphqlClientInterface
{
    public string $lastQuery = '';

    public function __construct(
        private readonly array $response,
    ) {
    }

    public function query(string $query, GraphqlExecutionContext $context, array $variables = []): array
    {
        $this->lastQuery = $query;

        return $this->response;
    }
}
