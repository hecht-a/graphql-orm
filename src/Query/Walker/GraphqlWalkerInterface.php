<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\QueryOptions;

interface GraphqlWalkerInterface
{
    public function walk(QueryNode $query, QueryOptions $options): string;
}
