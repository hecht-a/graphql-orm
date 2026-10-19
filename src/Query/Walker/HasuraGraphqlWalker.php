<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

use GraphqlOrm\Dialect\HasuraDialect;
use GraphqlOrm\Dialect\HasuraOrderDirection;
use GraphqlOrm\Query\Ast\FieldNode;

/**
 * Prints Hasura queries: collections are plain lists, without envelope, and `order_by` directions are enum literals.
 */
final class HasuraGraphqlWalker extends DefaultGraphqlWalker
{
    protected function walkRootField(FieldNode $field): void
    {
        parent::walkRootField(new FieldNode(
            $field->name,
            HasuraDialect::withPagination($field->arguments, $this->options),
            $field->selectionSet,
            $field->isCollection,
        ));
    }

    protected function formatEnum(\BackedEnum $value): string
    {
        return $value instanceof HasuraOrderDirection ? $value->value : parent::formatEnum($value);
    }
}
