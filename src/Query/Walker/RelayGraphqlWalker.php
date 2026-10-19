<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

use GraphqlOrm\Dialect\CursorPageArguments;
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
        $this->printer->line($field->name . $this->formatRootArguments(CursorPageArguments::apply($field->arguments, $this->options)) . ' {');
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
}
