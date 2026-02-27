<?php

declare(strict_types=1);

namespace GraphqlOrm\DependencyInjection;

use GraphqlOrm\Dialect\DefaultDialect;
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

            ->arrayNode('http_client_options')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('verify_host')
            ->defaultValue(true)
            ->end()
            ->end()
            ->children()
            ->booleanNode('verify_peer')
            ->defaultValue(true)
            ->end()
            ->end()
            ->end()

            ->scalarNode('dialect')
            ->defaultValue(DefaultDialect::class)
            ->end()

            ->arrayNode('headers')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()

            ->integerNode('max_depth')
            ->defaultValue(2)
            ->min(1)
            ->end()

            ->arrayNode('schema_validation')
            ->addDefaultsIfNotSet()
            ->children()
            ->enumNode('mode')
            ->values(['exception', 'warning', 'disabled'])
            ->defaultValue('disabled')
            ->info('What to do when entity mapping does not match the GraphQL schema. "exception" blocks the boot, "warning" logs a warning, "disabled" skips validation.')
            ->end()
            ->end()
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
