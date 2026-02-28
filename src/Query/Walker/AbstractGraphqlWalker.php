<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

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

    protected function walkSelectionSet(SelectionSetNode $selectionSet): void
    {
        foreach ($selectionSet->fields as $child) {
            $this->walkField($child);
        }
    }
}
