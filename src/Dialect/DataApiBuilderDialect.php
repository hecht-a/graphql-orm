<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Query\Walker\DABGraphqlWalker;
use GraphqlOrm\Query\Walker\GraphqlWalkerInterface;

final class DataApiBuilderDialect implements GraphqlQueryDialect
{
    public function extractCollection(array $data): array
    {
        /** @var array<string|int, mixed> $items */
        $items = $data['items'] ?? [];

        return $items;
    }

    public function createWalker(): GraphqlWalkerInterface
    {
        return new DABGraphqlWalker();
    }
}
