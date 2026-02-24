<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Query\Walker\DefaultGraphqlWalker;
use GraphqlOrm\Query\Walker\GraphqlWalkerInterface;

final class DefaultDialect implements GraphqlQueryDialect
{
    public function extractCollection(array $data): array
    {
        return $data;
    }

    public function createWalker(): GraphqlWalkerInterface
    {
        return new DefaultGraphqlWalker();
    }
}
