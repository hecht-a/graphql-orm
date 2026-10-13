<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

use GraphqlOrm\Dialect\HasuraOrderDirection;
use GraphqlOrm\Query\Ast\SelectionSetNode;

/**
 * Prints Hasura queries: collections are plain lists, without envelope, and `order_by` directions are enum literals.
 */
final class HasuraGraphqlWalker extends DefaultGraphqlWalker
{
    protected function walkCollection(SelectionSetNode $selectionSet): void
    {
        $this->walkSelectionSet($selectionSet);
    }

    protected function formatValue(mixed $value): string
    {
        if ($value instanceof HasuraOrderDirection) {
            return $value->value;
        }

        return parent::formatValue($value);
    }
}
