<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

use GraphqlOrm\Dialect\CursorPageArguments;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Direction;

final class DABGraphqlWalker extends AbstractGraphqlWalker
{
    protected function walkRootField(FieldNode $field): void
    {
        $arguments = array_filter(CursorPageArguments::apply($field->arguments, $this->options), fn ($value) => $value !== null);
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
     * The order directions of Data API Builder are enum literals.
     */
    protected function formatEnum(\BackedEnum $value): string
    {
        return $value instanceof Direction ? $value->value : parent::formatEnum($value);
    }

    /**
     * Data API Builder refuses a null argument.
     */
    protected function omitsArgument(mixed $value): bool
    {
        return $value === null;
    }
}
