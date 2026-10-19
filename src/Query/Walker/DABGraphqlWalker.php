<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

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

        // The root arguments hold the cursor the query was built with: the one of the page being fetched replaces it,
        // and the first page has none.
        unset($arguments['after']);

        if ($this->options->cursor !== null) {
            $arguments['after'] = $this->options->cursor;
        }

        return $arguments;
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
