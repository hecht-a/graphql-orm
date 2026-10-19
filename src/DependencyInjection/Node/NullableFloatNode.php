<?php

declare(strict_types=1);

namespace GraphqlOrm\DependencyInjection\Node;

use Symfony\Component\Config\Definition\FloatNode;

/**
 * @internal
 */
final class NullableFloatNode extends FloatNode
{
    use AcceptsNull;
}
