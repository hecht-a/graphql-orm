<?php

declare(strict_types=1);

namespace GraphqlOrm\DependencyInjection\Node;

use Symfony\Component\Config\Definition\IntegerNode;

/**
 * @internal
 */
final class NullableIntegerNode extends IntegerNode
{
    use AcceptsNull;
}
