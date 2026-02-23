<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

final class DataApiBuilderDialect implements GraphqlQueryDialect
{
    public function wrapCollection(string $selection, int $indentLevel): string
    {
        $indent = str_repeat('  ', $indentLevel);
        $innerIndent = str_repeat('  ', $indentLevel - 1);

        $selection = $this->indent($selection, $innerIndent);

        return <<<GQL
{$indent}items {
$selection
{$indent}}
GQL;
    }

    public function extractCollection(array $data): array
    {
        /** @var array<string|int, mixed> $items */
        $items = $data['items'] ?? [];

        return $items;
    }

    private function indent(string $text, string $indent): string
    {
        return implode(
            "\n",
            array_map(
                fn ($line) => $line !== '' ? $indent . $line : $line,
                explode("\n", $text)
            )
        );
    }
}
