<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;

/**
 * Prints Relay connections: the root field and nested collections select `edges { node { ... } }`, and the root field
 * selects `pageInfo` when the query is paginated.
 */
final class RelayGraphqlWalker extends DefaultGraphqlWalker
{
    protected function walkRootField(FieldNode $field): void
    {
        $this->printer->line($field->name . $this->formatRootArguments($this->withPaginationArguments($field->arguments)) . ' {');
        $this->printer->indent();

        $this->walkCollection($field->selectionSet ?? new SelectionSetNode());

        if ($this->options->paginate) {
            $this->printer->line('pageInfo {');
            $this->printer->indent();
            $this->printer->line('hasNextPage');
            $this->printer->line('endCursor');
            $this->printer->outdent();
            $this->printer->line('}');
        }

        $this->printer->outdent();
        $this->printer->line('}');
    }

    protected function walkCollection(SelectionSetNode $selectionSet): void
    {
        $this->printer->line('edges {');
        $this->printer->indent();
        $this->printer->line('node {');
        $this->printer->indent();

        $this->walkSelectionSet($selectionSet);

        $this->printer->outdent();
        $this->printer->line('}');
        $this->printer->outdent();
        $this->printer->line('}');
    }

    /**
     * The root arguments are computed when the query is built: the cursor of the page being fetched is applied here.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function withPaginationArguments(array $arguments): array
    {
        if (!$this->options->paginate) {
            return $arguments;
        }

        if ($this->options->limit !== null) {
            $arguments['first'] = $this->options->limit;
        }

        unset($arguments['after']);

        if ($this->options->cursor !== null) {
            $arguments['after'] = $this->options->cursor;
        }

        return $arguments;
    }
}
