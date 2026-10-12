<?php

declare(strict_types=1);

namespace GraphqlOrm\DependencyInjection;

use GraphqlOrm\Dialect\DefaultDialect;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * @internal
 */
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

            ->booleanNode('redact_query_values')
            ->defaultTrue()
            ->info('Mask the values of GraphQL queries (strings, numbers, variables) in logs and in the profiler, as they may hold personal data. The query sent to the API is not affected.')
            ->end()

            ->arrayNode('variables')
            ->addDefaultsIfNotSet()
            ->info('Send root query arguments as typed GraphQL variables; their types come from a cached introspection of the schema.')
            ->children()
            ->booleanNode('enabled')->defaultFalse()->end()
            ->scalarNode('cache_pool')->defaultValue('cache.app')->cannotBeEmpty()->end()
            ->integerNode('ttl')->defaultValue(3600)->min(0)->info('Seconds before the schema is introspected again.')->end()
            ->end()
            ->end()

            ->scalarNode('http_client')
            ->defaultNull()
            ->info('Id of the HTTP client service to use, e.g. a scoped client configured with a timeout and retries. Defaults to the autowired HttpClientInterface.')
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
