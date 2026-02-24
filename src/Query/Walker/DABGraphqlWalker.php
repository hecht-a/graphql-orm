<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Printer\GraphqlPrinter;

final class DABGraphqlWalker extends AbstractGraphqlWalker
{
    public function walk(QueryNode $query): string
    {
        $this->printer = new GraphqlPrinter();

        $this->printer->line($query->operation . ' {');

        $this->printer->indent();

        foreach ($query->fields as $field) {
            $this->walkRootField($field);
        }

        $this->printer->outdent();

        $this->printer->line('}');

        return $this->printer->get();
    }

    private function walkRootField(FieldNode $field): void
    {
        $args = $this->formatArguments($field->arguments);

        $this->printer->line($field->name . $args . ' {');

        $this->printer->indent();

        $this->printer->line('items {');

        $this->printer->indent();

        if ($field->selectionSet !== null) {
            $this->walkSelectionSet($field->selectionSet);
        }

        $this->printer->outdent();

        $this->printer->line('}');

        $this->printer->outdent();

        $this->printer->line('}');
    }
}
