<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures;

use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\Execution\GraphqlExecutionContext;

final readonly class FakeGraphqlClient implements GraphqlClientInterface
{
    public function __construct(
        private array $response,
    ) {
    }

    public function query(string $query, GraphqlExecutionContext $context, array $variables = []): array
    {
        return $this->response;
    }
}
