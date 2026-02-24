<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Query\Walker\GraphqlWalkerInterface;

interface GraphqlQueryDialect
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array<string|int, mixed>
     */
    public function extractCollection(array $data): array;

    public function createWalker(): GraphqlWalkerInterface;
}
