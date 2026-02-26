<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures;

use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\Execution\GraphqlExecutionContext;

final class FakeGraphqlClient implements GraphqlClientInterface
{
    public string $lastQuery = '';
    private int $callCount = 0;
    private array $responses;

    public function __construct(array ...$responses)
    {
        $this->responses = $responses;
    }

    public function query(string $query, GraphqlExecutionContext $context, array $variables = []): array
    {
        $this->lastQuery = $query;

        return $this->responses[$this->callCount++] ?? [];
    }
}
