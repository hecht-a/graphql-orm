<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Direction;

final class DABGraphqlWalker extends AbstractGraphqlWalker
{
    protected function walkRootField(FieldNode $field): void
    {
        $arguments = array_filter($this->applyPaginationArguments($field->arguments), fn ($value) => $value !== null);
        $args = $this->formatRootArguments($arguments);

        $this->printer->line($field->name . $args . ' {');

        $this->printer->indent();

        $this->printer->line('items {');

        $this->printer->indent();

        if ($field->selectionSet !== null) {
            $this->walkSelectionSet($field->selectionSet);
        }

        $this->printer->outdent();

        $this->printer->line('}');

        if ($this->options->paginate) {
            $this->printer->line('hasNextPage');
            $this->printer->line('endCursor');
        }

        $this->printer->outdent();

        $this->printer->line('}');
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function applyPaginationArguments(array $arguments): array
    {
        if (!$this->options->paginate) {
            return $arguments;
        }

        if ($this->options->limit !== null) {
            $arguments['first'] = $this->options->limit;
        }

        if ($this->options->cursor !== null) {
            $arguments['after'] = $this->options->cursor;
        }

        return $arguments;
    }

    protected function formatValue(mixed $value): string
    {
        if (\is_string($value)) {
            return $this->formatString($value);
        }

        if (\is_int($value) || \is_float($value)) {
            return $this->formatNumber($value);
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (\is_array($value)) {
            if (array_is_list($value)) {
                $items = array_map(fn ($v) => $this->formatValue($v), $value);

                return '[' . implode(', ', $items) . ']';
            }

            $fields = [];
            foreach ($value as $key => $item) {
                $fields[] = $this->assertValidName($key) . ': ' . $this->formatValue($item);
            }

            return '{ ' . implode(', ', $fields) . ' }';
        }

        if ($value instanceof \BackedEnum) {
            return $value instanceof Direction
                ? $value->value
                : $this->formatString((string) $value->value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $this->formatString($value->format(DATE_ATOM));
        }

        throw new InvalidArgumentException(\sprintf('Unsupported GraphQL argument type "%s".', get_debug_type($value)));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function formatArguments(array $arguments): string
    {
        if ($arguments === []) {
            return '';
        }

        $pairs = [];

        foreach ($arguments as $key => $value) {
            if ($value === null) {
                continue;
            }
            $pairs[] = \sprintf('%s: %s', $this->assertValidName($key), $this->formatValue($value));
        }

        return '(' . implode(', ', $pairs) . ')';
    }
}
