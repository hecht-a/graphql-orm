<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures;

use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\Execution\GraphqlExecutionContext;

final class FakeGraphqlClient implements GraphqlClientInterface
{
    public string $lastQuery = '';
    /** @var array<string, mixed> */
    public array $lastVariables = [];
    public int $callCount = 0;
    /** @var list<array<string, mixed>> */
    private array $responses;

    /**
     * @param array<string, mixed> ...$responses
     */
    public function __construct(array ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function query(string $query, GraphqlExecutionContext $context, array $variables = []): array
    {
        $this->lastQuery = $query;
        $this->lastVariables = $variables;

        return $this->responses[$this->callCount++] ?? [];
    }
}
