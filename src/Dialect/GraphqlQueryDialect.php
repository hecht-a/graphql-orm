<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

interface GraphqlQueryDialect
{
    public function wrapCollection(string $selection, int $indentLevel): string;

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string|int, mixed>
     */
    public function extractCollection(array $data): array;
}
