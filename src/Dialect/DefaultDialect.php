<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

final class DefaultDialect implements GraphqlQueryDialect
{
    public function wrapCollection(string $selection, int $indentLevel): string
    {
        return $selection;
    }

    public function extractCollection(array $data): array
    {
        return $data;
    }
}
