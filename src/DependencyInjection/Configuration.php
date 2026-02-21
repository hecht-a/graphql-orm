<?php

declare(strict_types=1);

namespace GraphqlOrm\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('graphql_orm');

        $treeBuilder->getRootNode()
            ->children()

            ->scalarNode('endpoint')
            ->isRequired()
            ->cannotBeEmpty()
            ->end()

            ->arrayNode('headers')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()

            ->integerNode('max_depth')
            ->defaultValue(2)
            ->min(1)
            ->end()

            ->end();

        return $treeBuilder;
    }
}
