<?php

declare(strict_types=1);

namespace GraphqlOrm\DependencyInjection;

use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Metadata\Naming\DefaultNamingStrategy;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * The options of a GraphQL API, at the root and for each of the managers: those a manager does not set are taken from
 * the root, which holds the defaults (see ManagerConfigs).
 *
 * @internal
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('graphql_orm');
        $root = $treeBuilder->getRootNode()->children();

        $root
            ->scalarNode('default_manager')
            ->defaultNull()
            ->info('The manager of the entities no mapping claims and of the services autowired without a name; the first of managers when null.')
            ->end();

        $manager = $root->arrayNode('managers')
            ->info('One manager per GraphQL API, as the entity managers of Doctrine; without managers, the options of the root are those of the manager "default".')
            ->useAttributeAsKey('name')
            ->arrayPrototype();
        $this->addOptions($manager->children(), defaults: false);
        $this->addOptions($root, defaults: true);

        return $treeBuilder;
    }

    /**
     * @param bool $defaults whether the options get their default values (the root) or are left out when unset (a
     *                       manager, which inherits them from the root)
     */
    private function addOptions(NodeBuilder $children, bool $defaults): void
    {
        $children->scalarNode('endpoint')->cannotBeEmpty()->info('Required, at the root or for each manager.');

        $this->arrayNode($children, 'http_client_options', $defaults)
            ->children()
            ->append($this->boolean('verify_host', true, $defaults))
            ->append($this->boolean('verify_peer', true, $defaults))
            ->end();

        $children->append($this->boolean('redact_query_values', true, $defaults)
            ->info('Mask the values of GraphQL queries (strings, numbers, variables) in logs and in the profiler, as they may hold personal data. The query sent to the API is not affected.'));

        $variables = $this->arrayNode($children, 'variables', $defaults)
            ->info('Send root query arguments as typed GraphQL variables; their types come from a cached introspection of the schema.');
        $variablesChildren = $variables->children();
        $variablesChildren->append($this->boolean('enabled', true, $defaults));
        $this->scalar($variablesChildren, 'cache_pool', 'cache.app', $defaults)->cannotBeEmpty();
        $ttl = $variablesChildren->integerNode('ttl')->min(0)->info('Seconds before the schema is introspected again.');
        $this->default($ttl, 3600, $defaults);

        $this->scalar($children, 'http_client', null, $defaults)
            ->info('Id of the HTTP client service to use, e.g. a scoped client configured with a timeout and retries. Defaults to the autowired HttpClientInterface.');
        $this->scalar($children, 'dialect', DefaultDialect::class, $defaults);

        $children->arrayNode('headers')
            ->normalizeKeys(false)
            ->scalarPrototype()->end()
            ->info('Sent with every request; those of a manager are added to those of the root.')
            ->defaultValue([])
            ->end();

        $children->append($this->boolean('identity_map', true, $defaults)
            ->info('Share the entities between the queries of a request: an entity is one instance, and find() serves one known in full without a query. Reset between requests and messages.'));

        $slow = $children->floatNode('slow_query_threshold')->min(0)
            ->info('Duration in milliseconds from which a query is logged as a warning and highlighted in the profiler; null reports none.');
        $this->default($slow, null, $defaults);

        $this->scalar($children, 'naming_strategy', DefaultNamingStrategy::class, $defaults)
            ->info('Id of a service implementing NamingStrategy, naming the fields whose #[GraphqlOrm\Field] or #[GraphqlOrm\Relation] has no "mappedFrom" from their property: DefaultNamingStrategy keeps the property name, SnakeCaseNamingStrategy turns dueDate into due_date.');

        $this->scalar($this->arrayNode($children, 'result_cache', $defaults)
            ->info('Keeps the responses of the queries calling enableResultCache() between requests.')
            ->children(), 'pool', 'cache.app', $defaults)
            ->info('Id of the cache pool keeping them, e.g. a pool of framework.cache.pools with the Redis adapter; null disables the result cache.');

        $secondLevel = $this->arrayNode($children, 'second_level_cache', $defaults)
            ->info('Keeps the entities marked #[GraphqlOrm\Cache] between requests once hydrated in full: find(), the references and the lazy loads read them from there.')
            ->children();
        $secondLevel->append($this->boolean('enabled', false, $defaults)->info('Disabled by default, as in Doctrine: #[GraphqlOrm\Cache] is ignored until it is enabled.'));
        $this->scalar($secondLevel, 'pool', 'cache.app', $defaults)
            ->cannotBeEmpty()
            ->info('Id of the cache pool keeping the entities without region, e.g. a pool of framework.cache.pools with the Redis adapter.');
        $lifetime = $secondLevel->integerNode('default_lifetime')->min(1)->info('Seconds an entity is kept, unless its region or its #[GraphqlOrm\Cache] says otherwise.');
        $this->default($lifetime, 3600, $defaults);
        $secondLevel->arrayNode('regions')
            ->info('Named by #[GraphqlOrm\Cache(region: ...)]: a pool and a lifetime for some entities, e.g. reference data kept a day in Redis.')
            ->useAttributeAsKey('name')
            ->arrayPrototype()
            ->children()
            ->scalarNode('pool')->defaultNull()->info('The pool of second_level_cache when null.')->end()
            ->integerNode('lifetime')->defaultNull()->min(1)->info('The default_lifetime of second_level_cache when null.')->end()
            ->end()
            ->end()
            ->end();

        $this->scalar($children, 'metadata_cache_pool', 'cache.system', $defaults)
            ->info('Cache pool keeping the entity metadata between requests, filled by cache:warmup. Not used in debug mode, so that mapping changes are seen immediately; null disables it.');
        $this->scalar($children, 'headers_provider', null, $defaults)
            ->info('Id of a service implementing HeadersProviderInterface, whose headers are computed before every request (e.g. an OAuth token) and override the static headers.');

        $children->append($this->boolean('lazy_loading', false, $defaults)
            ->info('Hydrate the relations reduced to their identifier (beyond max_depth) as lazy ghosts, loaded with an extra query on first access.'));
        $maxDepth = $children->integerNode('max_depth')->min(1);
        $this->default($maxDepth, 1, $defaults);

        $mode = $this->arrayNode($children, 'schema_validation', $defaults)
            ->children()
            ->enumNode('mode')
            ->values(['exception', 'warning', 'disabled', null])
            ->info('What to do when entity mapping does not match the GraphQL schema. "exception" blocks the boot, "warning" logs a warning, "disabled" skips validation. Null: "warning" in the dev environment, "disabled" otherwise.');
        $this->default($mode, null, $defaults);

        $mapping = $this->arrayNode($children, 'mapping', $defaults)
            ->info('Where the entities and repositories of the API are: an entity belongs to the manager whose entity namespace holds it, unless its #[GraphqlOrm\Entity] names one.')
            ->children();
        foreach (['entity' => 'Entity', 'repository' => 'Repository'] as $name => $folder) {
            $node = $this->arrayNode($mapping, $name, $defaults)->children();
            $this->scalar($node, 'dir', '%kernel.project_dir%/src/GraphQL/' . $folder, $defaults);
            $this->scalar($node, 'namespace', 'App\\GraphQL\\' . $folder, $defaults);
        }
    }

    private function arrayNode(NodeBuilder $children, string $name, bool $defaults): ArrayNodeDefinition
    {
        $node = $children->arrayNode($name);

        if ($defaults) {
            $node->addDefaultsIfNotSet();
        }

        return $node;
    }

    private function scalar(NodeBuilder $children, string $name, ?string $default, bool $defaults): NodeDefinition
    {
        $node = $children->scalarNode($name);
        $this->default($node, $default, $defaults);

        return $node;
    }

    private function boolean(string $name, bool $default, bool $defaults): NodeDefinition
    {
        $node = (new TreeBuilder($name, 'boolean'))->getRootNode();
        $this->default($node, $default, $defaults);

        return $node;
    }

    private function default(NodeDefinition $node, mixed $default, bool $defaults): void
    {
        if ($defaults) {
            $node->defaultValue($default);
        }
    }
}
