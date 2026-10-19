<?php

declare(strict_types=1);

namespace GraphqlOrm\DependencyInjection\Node;

use Symfony\Component\Config\Definition\Builder\FloatNodeDefinition;

/**
 * @internal
 */
final class NullableFloatNodeDefinition extends FloatNodeDefinition
{
    protected function instantiateNode(): NullableFloatNode
    {
        return new NullableFloatNode($this->name, $this->parent, $this->min, $this->max, $this->pathSeparator);
    }
}
