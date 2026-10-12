<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;
use GraphqlOrm\Query\GraphqlName;
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

        if ($field->isCollection) {
            $this->printer->line('items {');
            $this->printer->indent();
        }

        $this->walkSelectionSet($field->selectionSet);

        if ($field->isCollection) {
            $this->printer->outdent();
            $this->printer->line('}');
        }

        $this->printer->outdent();

        $this->printer->line('}');
    }

    abstract protected function formatValue(mixed $value): string;

    /**
     * @param array<string, mixed> $arguments
     */
    abstract protected function formatArguments(array $arguments): string;

    /**
     * GraphQL string escapes are a subset of JSON ones, so a JSON-encoded string is a valid GraphQL string literal.
     */
    protected function formatString(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @throws InvalidArgumentException when the value has no GraphQL literal (INF, NAN)
     */
    protected function formatNumber(int|float $value): string
    {
        if (\is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('Non-finite floats (INF, NAN) cannot be sent as GraphQL arguments.');
        }

        return (string) $value;
    }

    /**
     * @throws InvalidArgumentException when the name could break out of the generated query
     */
    protected function assertValidName(string|int $name): string
    {
        if (!\is_string($name) || !GraphqlName::isValid($name)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a valid GraphQL name.', $name));
        }

        return $name;
    }

    protected function walkSelectionSet(SelectionSetNode $selectionSet): void
    {
        foreach ($selectionSet->fields as $child) {
            $this->walkField($child);
        }
    }
}
