<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Printer\GraphqlPrinter;

final class DefaultGraphqlWalker extends AbstractGraphqlWalker
{
    public function walk(QueryNode $query): string
    {
        $this->printer = new GraphqlPrinter();

        $this->printer->line($query->operation . ' {');

        $this->printer->indent();

        foreach ($query->fields as $field) {
            $this->walkField($field);
        }

        $this->printer->outdent();

        $this->printer->line('}');

        return $this->printer->get();
    }
}
