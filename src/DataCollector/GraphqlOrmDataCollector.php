<?php

declare(strict_types=1);

namespace GraphqlOrm\DataCollector;

use GraphqlOrm\Query\GraphqlQueryTrace;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

class GraphqlOrmDataCollector extends DataCollector
{
    public function addQuery(
        GraphqlQueryTrace $trace,
    ): void {
        $this->data['queries'][] = [
            'graphql' => $trace->graphql,
            'variables' => $trace->variables,
            'endpoint' => $trace->endpoint,
            'caller' => $trace->caller,
            'response_size' => $trace->responseSize,
            'errors' => $trace->errors,
            'hydrated_count' => $trace->hydratedCount,
            'depth_used' => $trace->depthUsed,
            'hydrated_collections' => $trace->hydratedCollections,
            'hydrated_entities' => $trace->hydratedEntities,
            'hydrated_relations' => $trace->hydratedRelations,
        ];
    }

    public function collect(
        Request $request,
        Response $response,
        ?\Throwable $exception = null,
    ): void {
        $this->data['queries'] ??= [];
    }

    public function reset(): void
    {
        $this->data = [];
    }

    /**
     * @return array<int, array<string, array<string, mixed>>>
     */
    public function getQueries(): array
    {
        return $this->data['queries'] ?? [];
    }

    public function getQueryCount(): int
    {
        return \count($this->getQueries());
    }

    public function getTotalDuration(): float
    {
        return array_sum(
            array_column(
                $this->getQueries(),
                'duration'
            )
        );
    }

    public function getErrorCount(): int
    {
        $count = 0;

        foreach ($this->getQueries() as $query) {
            if (!empty($query['errors'])) {
                ++$count;
            }
        }

        return $count;
    }

    public function getName(): string
    {
        return 'graphql_orm';
    }
}
