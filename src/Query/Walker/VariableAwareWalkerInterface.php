<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\CompiledQuery;
use GraphqlOrm\Query\QueryOptions;

interface VariableAwareWalkerInterface extends GraphqlWalkerInterface
{
    /**
     * Sends the root field arguments as variables when all their GraphQL types are known, inline values otherwise.
     *
     * @param array<string, string> $argumentTypes GraphQL type of each root argument, e.g. ['filter' => 'TaskFilterInput']
     */
    public function walkWithVariables(QueryNode $query, QueryOptions $options, array $argumentTypes): CompiledQuery;
}
