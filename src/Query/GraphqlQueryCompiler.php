<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Walker\GraphqlWalkerInterface;
use GraphqlOrm\Query\Walker\VariableAwareWalkerInterface;

/**
 * @internal
 */
final readonly class GraphqlQueryCompiler
{
    public function __construct(
        private GraphqlWalkerInterface $walker,
    ) {
    }

    public function compile(QueryNode $node, QueryOptions $options): string
    {
        return $this->walker->walk($node, $options);
    }

    /**
     * @param array<string, string> $argumentTypes
     */
    public function compileWithVariables(QueryNode $node, QueryOptions $options, array $argumentTypes): CompiledQuery
    {
        if (!$this->walker instanceof VariableAwareWalkerInterface) {
            return new CompiledQuery($this->walker->walk($node, $options));
        }

        return $this->walker->walkWithVariables($node, $options, $argumentTypes);
    }
}
