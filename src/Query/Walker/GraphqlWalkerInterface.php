<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

use GraphqlOrm\Query\Ast\QueryNode;

interface GraphqlWalkerInterface
{
    public function walk(QueryNode $query): string;
}
