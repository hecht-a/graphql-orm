<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Ast;

final class SelectionSetNode
{
    /** @var FieldNode[] */
    public array $fields = [];

    public function add(FieldNode $field): void
    {
        $this->fields[] = $field;
    }
}
