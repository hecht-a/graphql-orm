<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;
use GraphqlOrm\Query\Printer\GraphqlPrinter;

abstract class AbstractGraphqlWalker implements GraphqlWalkerInterface
{
    protected GraphqlPrinter $printer;

    protected function walkField(FieldNode $field): void
    {
        $args = $this->formatArguments($field->arguments);

        if ($field->selectionSet === null) {
            $this->printer->line($field->name . $args);

            return;
        }

        $this->printer->line($field->name . $args . ' {');

        $this->printer->indent();

        $this->walkSelectionSet($field->selectionSet);

        $this->printer->outdent();

        $this->printer->line('}');
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
            $items = array_map(
                fn ($v) => $this->formatValue($v),
                $value
            );

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

    protected function walkSelectionSet(SelectionSetNode $selectionSet): void
    {
        foreach ($selectionSet->fields as $child) {
            $this->walkField($child);
        }
    }
}
