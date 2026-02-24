<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Walker\GraphqlWalkerInterface;

readonly class GraphqlQueryCompiler
{
    public function __construct(
        private GraphqlWalkerInterface $walker,
    ) {
    }

    public function compile(QueryNode $node): string
    {
        return $this->walker->walk($node);
    }
}
