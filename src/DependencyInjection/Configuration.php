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

            ->arrayNode('mapping')
            ->addDefaultsIfNotSet()
            ->children()

            ->arrayNode('entity')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('dir')->defaultValue('%kernel.project_dir%/src/GraphQL/Entity')->end()
            ->scalarNode('namespace')->defaultValue('App\\GraphQL\\Entity')->end()
            ->end()
            ->end()

            ->arrayNode('repository')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('dir')->defaultValue('%kernel.project_dir%/src/GraphQL/Repository')->end()
            ->scalarNode('namespace')->defaultValue('App\\GraphQL\\Repository')->end()
            ->end()
            ->end()

            ->end()
            ->end()

            ->end()
        ;

        return $treeBuilder;
    }
}
