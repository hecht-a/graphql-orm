<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Printer\GraphqlPrinter;
use GraphqlOrm\Query\QueryOptions;

final class DefaultGraphqlWalker extends AbstractGraphqlWalker
{
    public function walk(QueryNode $query, QueryOptions $options): string
    {
        $this->printer = new GraphqlPrinter();

        $this->printer->line($query->operation . ' {');

        $this->printer->indent();

        foreach ($query->fields as $field) {
            $this->walkField($field);
        }

        $this->printer->outdent();

        $this->printer->line('}');

        return $this->printer->get();
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
            $items = array_map(fn ($v) => $this->formatValue($v), $value);

            return '[' . implode(', ', $items) . ']';
        }

        if ($value instanceof \BackedEnum) {
            return '"' . $value->value . '"';
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
            $pairs[] = \sprintf('%s: %s', $key, $this->formatValue($value));
        }

        return '(' . implode(', ', $pairs) . ')';
    }
}
