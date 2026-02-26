<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\Printer\GraphqlPrinter;
use GraphqlOrm\Query\QueryOptions;

final class DABGraphqlWalker extends AbstractGraphqlWalker
{
    private QueryOptions $options;

    public function walk(QueryNode $query, QueryOptions $options): string
    {
        $this->options = $options;

        $this->printer = new GraphqlPrinter();

        $this->printer->line($query->operation . ' {');

        $this->printer->indent();

        foreach ($query->fields as $field) {
            $this->walkRootField($field);
        }

        $this->printer->outdent();

        $this->printer->line('}');

        return $this->printer->get();
    }

    private function walkRootField(FieldNode $field): void
    {
        $args = $this->formatArguments($this->applyPaginationArguments($field->arguments));

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
            return '"' . $value . '"';
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
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
                $fields[] = $key . ': ' . $this->formatValue($item);
            }

            return '{ ' . implode(', ', $fields) . ' }';
        }

        if ($value instanceof \BackedEnum) {
            return $value instanceof Direction
                ? $value->value
                : '"' . $value->value . '"';
        }

        if ($value instanceof \DateTimeInterface) {
            return '"' . $value->format(DATE_ATOM) . '"';
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
            $pairs[] = \sprintf('%s: %s', $key, $this->formatValue($value));
        }

        return '(' . implode(', ', $pairs) . ')';
    }
}
