<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;

class DefaultGraphqlWalker extends AbstractGraphqlWalker
{
    protected function walkRootField(FieldNode $field): void
    {
        $this->walkField($field, isRoot: true);
    }

    /**
     * Standard GraphQL collections are plain lists: no envelope around the selection.
     */
    protected function walkCollection(SelectionSetNode $selectionSet): void
    {
        $this->walkSelectionSet($selectionSet);
    }
}
