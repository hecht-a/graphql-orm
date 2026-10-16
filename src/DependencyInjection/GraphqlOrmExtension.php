<?php

declare(strict_types=1);

namespace GraphqlOrm\DependencyInjection;

use GraphqlOrm\Cache\CachePoolDescriber;
use GraphqlOrm\Cache\GraphqlCache;
use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\EventListener\SchemaValidationListener;
use GraphqlOrm\Execution\IdentityMap;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\GraphqlManagerInterface;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\MetadataCacheWarmer;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Schema\CountableFieldResolver;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Schema\SchemaValidationMode;
use GraphqlOrm\Schema\SchemaValidator;
use GraphqlOrm\Test\MockGraphqlServer;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Registers a manager per GraphQL API, its services named graphql_orm.<manager>.<service>; those of the default manager
 * are also the services of their class, so that a single API is autowired as before.
 *
 * @phpstan-import-type ManagerOptions from ManagerConfigs
 *
 * @internal
 */
final class GraphqlOrmExtension extends Extension
{
    /** The services of a manager given by their class to the autowiring when the manager is the default one. */
    private const DEFAULT_ALIASES = [
        GraphqlManager::class => 'manager',
        GraphqlManagerInterface::class => 'manager',
        GraphqlCache::class => 'cache',
        GraphqlEntityMetadataFactory::class => 'metadata_factory',
        EntityHydrator::class => 'hydrator',
        GraphqlClient::class => 'client',
        GraphqlClientInterface::class => 'client',
        'graphql_orm.dialect' => 'dialect',
        SchemaIntrospector::class => 'schema_introspector',
        SchemaValidator::class => 'schema_validator',
        SchemaValidationListener::class => 'schema_validation_listener',
        'graphql_orm.schema_validation_mode' => 'schema_validation_mode',
        RootArgumentTypeResolver::class => 'argument_types',
        CountableFieldResolver::class => 'countable_fields',
        IdentityMap::class => 'identity_map',
        MetadataCacheWarmer::class => 'metadata_cache_warmer',
    ];

    /** The services of a manager autowired by the name of their argument, e.g. GraphqlManagerInterface $crmManager. */
    private const NAMED_ALIASES = [
        GraphqlManagerInterface::class => ['manager', 'Manager'],
        GraphqlManager::class => ['manager', 'Manager'],
        GraphqlCache::class => ['cache', 'Cache'],
    ];

    private const REPOSITORY_TAG = 'graphql_orm.repository';

    private const LOGGER = 'monolog.logger.graphql_orm';

    /** Before the other listeners of the request, as the routing: the schema is checked before the controller runs. */
    private const SCHEMA_VALIDATION_PRIORITY = 255;

    public function load(array $configs, ContainerBuilder $container): void
    {
        $managers = ManagerConfigs::fromProcessedConfiguration($this->processConfiguration(new Configuration(), $configs));

        (new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config')))->load('services.php');

        // The repositories of the application, given by GraphqlManager::getRepository().
        $container->registerForAutoconfiguration(GraphqlEntityRepository::class)->addTag(self::REPOSITORY_TAG);

        $debug = $container->hasParameter('kernel.debug') && $container->getParameter('kernel.debug') === true;
        $environment = $container->hasParameter('kernel.environment') ? $container->getParameter('kernel.environment') : null;
        $environment = \is_string($environment) ? $environment : null;
        $entityClasses = [];
        $cachePools = [];

        foreach ($managers->managers as $name => $config) {
            $entityClasses[$name] = $this->registerManager($container, $name, $config, $debug, $environment);
            $cachePools = [...$cachePools, ...$this->cachePools($config, \count($managers->managers) > 1 ? $name . ': ' : '')];
        }

        $this->aliasDefaultManager($container, $managers->defaultManager);

        $container->setParameter('graphql_orm.default_manager', $managers->defaultManager);
        $container->setParameter('graphql_orm.managers', array_keys($managers->managers));
        $container->setParameter('graphql_orm.schema_validation.entity_classes', array_values(array_unique(array_merge(...array_values($entityClasses)))));

        $default = $managers->managers[$managers->defaultManager];
        $this->setDefaultParameters($container, $default);
        $container->setParameter('graphql_orm.schema_validation.mode', self::schemaValidationMode($default, $environment));

        // The collector keeps every query in memory: only useful when the profiler can display it.
        if (!$debug) {
            $container->removeDefinition(GraphqlOrmDataCollector::class);

            return;
        }

        $container->register(CachePoolDescriber::class, CachePoolDescriber::class)->setArguments([$cachePools]);
        $container->getDefinition(GraphqlOrmDataCollector::class)
            ->setArgument('$slowQueryThreshold', self::slowQueryThreshold($default))
            ->setArgument('$configuration', $this->profiledConfiguration($default, array_keys($managers->managers)))
            ->setArgument('$cachePools', new Reference(CachePoolDescriber::class));
    }

    /**
     * The services of a manager, named graphql_orm.<manager>.<service>.
     *
     * @param ManagerOptions $config
     *
     * @return class-string[] the entities of its mapping directory
     */
    private function registerManager(ContainerBuilder $container, string $name, array $config, bool $debug, ?string $environment): array
    {
        $id = static fn (string $service): string => 'graphql_orm.' . $name . '.' . $service;
        $collector = new Reference(GraphqlOrmDataCollector::class, ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $logger = new Reference(self::LOGGER, ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $entityClasses = $this->entityClasses($container, $config);

        $metadataFactory = $container->register($id('metadata_factory'), GraphqlEntityMetadataFactory::class)
            ->setArgument('$namingStrategy', new Reference($config['naming_strategy']));

        if (!$debug && $config['metadata_cache_pool'] !== null) {
            $metadataFactory->setArgument('$cache', new Reference($config['metadata_cache_pool']));
            $container->register($id('metadata_cache_warmer'), MetadataCacheWarmer::class)
                ->setArguments([new Reference($id('metadata_factory')), $entityClasses])
                ->addTag('kernel.cache_warmer');
        }

        $container->register($id('hydrator'), EntityHydrator::class)
            ->setArgument('$metadataFactory', new Reference($id('metadata_factory')));

        $this->registerClient($container, $id('client'), $config);

        $variablesPool = new Reference($config['variables']['cache_pool']);
        $dialect = $container->register($id('dialect'), $config['dialect'])
            ->setAutowired(true)
            ->setAutoconfigured(true);

        // Data API Builder counts on a numeric field: the schema gives one when the identifier is no number.
        if (is_a($config['dialect'], DataApiBuilderDialect::class, true)) {
            $container->register($id('countable_fields'), CountableFieldResolver::class)
                ->setArguments([new Reference($id('client')), $variablesPool, $config['variables']['ttl'], $logger, $collector]);
            $dialect->setArgument('$countableFields', new Reference($id('countable_fields')));
        }

        $cache = $container->register($id('cache'), GraphqlCache::class)
            ->setArgument('$collector', $collector)
            ->addTag('kernel.reset', ['method' => 'reset']);
        $this->configureCache($cache, $config['result_cache']['pool'], $config['second_level_cache']);

        $manager = $container->register($id('manager'), GraphqlManager::class)
            ->setArguments([
                '$metadataFactory' => new Reference($id('metadata_factory')),
                '$client' => new Reference($id('client')),
                '$hydrator' => new Reference($id('hydrator')),
                '$collector' => $collector,
                '$maxDepth' => $config['max_depth'],
                '$dialect' => new Reference($id('dialect')),
                '$logger' => $logger,
                '$redactor' => $config['redact_query_values'] ? new Reference(QueryRedactor::class) : null,
                '$eventDispatcher' => new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$lazyLoading' => $config['lazy_loading'],
                '$slowQueryThreshold' => self::slowQueryThreshold($config),
                '$repositories' => new ServiceLocatorArgument(new TaggedIteratorArgument(self::REPOSITORY_TAG, null, null, true)),
                '$cache' => new Reference($id('cache')),
            ])
            ->addTag('graphql.manager', ['name' => $name]);

        if ($config['variables']['enabled']) {
            $container->register($id('argument_types'), RootArgumentTypeResolver::class)
                ->setArguments([new Reference($id('client')), $variablesPool, $config['variables']['ttl'], $logger, $collector]);
            $manager->setArgument('$argumentTypeResolver', new Reference($id('argument_types')));
        }

        if ($config['identity_map']) {
            $container->register($id('identity_map'), IdentityMap::class)->addTag('kernel.reset', ['method' => 'reset']);
            $manager->setArgument('$identityMap', new Reference($id('identity_map')));
        }

        $this->registerSchemaValidation($container, $id, $config, $entityClasses, $environment);

        foreach (self::NAMED_ALIASES as $type => [$service, $suffix]) {
            $container->registerAliasForArgument($id($service), $type, $name . $suffix);
        }

        $container->setParameter($id('mapping.entity.dir'), $config['mapping']['entity']['dir']);
        $container->setParameter($id('mapping.entity.namespace'), $config['mapping']['entity']['namespace']);
        $container->setParameter($id('mapping.repository.dir'), $config['mapping']['repository']['dir']);
        $container->setParameter($id('mapping.repository.namespace'), $config['mapping']['repository']['namespace']);

        return $entityClasses;
    }

    /**
     * @param ManagerOptions $config
     */
    private function registerClient(ContainerBuilder $container, string $id, array $config): void
    {
        $client = $container->register($id, GraphqlClient::class)
            ->setAutowired(true)
            ->setArguments([
                '$endpoint' => $config['endpoint'],
                '$headers' => $config['headers'],
                '$httpClientOptions' => $config['http_client_options'],
                '$projectDir' => '%kernel.project_dir%',
                '$stopwatch' => new Reference(Stopwatch::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);

        // The mock server of the tests is fetched by them from the container.
        if ($config['http_client'] === MockGraphqlServer::class && !$container->hasDefinition(MockGraphqlServer::class)) {
            $container->register(MockGraphqlServer::class, MockGraphqlServer::class)->setPublic(true);
        }

        if ($config['http_client'] !== null) {
            $client->setArgument('$httpClient', new Reference($config['http_client']));
        }

        if ($config['headers_provider'] !== null) {
            $client->setArgument('$headersProvider', new Reference($config['headers_provider']));
        }
    }

    /**
     * Checks the mapping of the entities of a manager against the schema of its API on the first request.
     *
     * @param \Closure(string): string $id
     * @param ManagerOptions           $config
     * @param class-string[]           $entityClasses
     */
    private function registerSchemaValidation(ContainerBuilder $container, \Closure $id, array $config, array $entityClasses, ?string $environment): void
    {
        $collector = new Reference(GraphqlOrmDataCollector::class, ContainerInterface::NULL_ON_INVALID_REFERENCE);

        $container->register($id('schema_introspector'), SchemaIntrospector::class)
            ->setArguments([new Reference($id('client')), $collector]);
        $container->register($id('schema_validator'), SchemaValidator::class)
            ->setArguments([new Reference($id('metadata_factory'))]);

        $container->register($id('schema_validation_mode'), SchemaValidationMode::class)
            ->setFactory([SchemaValidationMode::class, 'from'])
            ->setArguments([self::schemaValidationMode($config, $environment)]);

        // Kept with the other introspections: a container per request does not introspect the schema on every request.
        $container->register($id('schema_validation_listener'), SchemaValidationListener::class)
            ->setArguments([
                '$introspector' => new Reference($id('schema_introspector')),
                '$validator' => new Reference($id('schema_validator')),
                '$mode' => new Reference($id('schema_validation_mode')),
                '$entityClasses' => $entityClasses,
                '$logger' => new Reference(self::LOGGER, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$collector' => $collector,
                '$cache' => new Reference($config['variables']['cache_pool']),
                '$ttl' => $config['variables']['ttl'],
            ])
            ->addTag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'priority' => self::SCHEMA_VALIDATION_PRIORITY, 'method' => '__invoke']);
    }

    /**
     * The services of the default manager are also those of their class.
     */
    private function aliasDefaultManager(ContainerBuilder $container, string $name): void
    {
        foreach (self::DEFAULT_ALIASES as $alias => $service) {
            $id = 'graphql_orm.' . $name . '.' . $service;

            if ($container->hasDefinition($id)) {
                $container->setAlias($alias, $id);
            }
        }
    }

    /**
     * The parameters of the default manager, read by the commands generating code.
     *
     * @param ManagerOptions $config
     */
    private function setDefaultParameters(ContainerBuilder $container, array $config): void
    {
        $container->setParameter('graphql_orm.endpoint', $config['endpoint']);
        $container->setParameter('graphql_orm.http_client_options', $config['http_client_options']);
        $container->setParameter('graphql_orm.dialect', $config['dialect']);
        $container->setParameter('graphql_orm.headers', $config['headers']);
        $container->setParameter('graphql_orm.max_depth', $config['max_depth']);
        $container->setParameter('graphql_orm.mapping.entity.dir', $config['mapping']['entity']['dir']);
        $container->setParameter('graphql_orm.mapping.entity.namespace', $config['mapping']['entity']['namespace']);
        $container->setParameter('graphql_orm.mapping.repository.dir', $config['mapping']['repository']['dir']);
        $container->setParameter('graphql_orm.mapping.repository.namespace', $config['mapping']['repository']['namespace']);
    }

    /**
     * The options shown by the profiler: the names of the headers only, and no credentials in the endpoint.
     *
     * @param ManagerOptions $config
     * @param list<string>   $managers
     *
     * @return array<string, mixed>
     */
    private function profiledConfiguration(array $config, array $managers): array
    {
        return [
            'managers' => $managers,
            'dialect' => $config['dialect'],
            'endpoint' => GraphqlOrmDataCollector::withoutCredentials($config['endpoint']),
            'headers' => array_keys($config['headers']),
            'headers_provider' => $config['headers_provider'],
            'http_client' => $config['http_client'],
            'max_depth' => $config['max_depth'],
            'identity_map' => $config['identity_map'],
            'lazy_loading' => $config['lazy_loading'],
            'variables' => $config['variables'],
            'naming_strategy' => $config['naming_strategy'],
            'slow_query_threshold' => self::slowQueryThreshold($config),
            'redact_query_values' => $config['redact_query_values'],
            'result_cache' => $config['result_cache'],
            'second_level_cache' => $config['second_level_cache'],
            'schema_validation' => '%graphql_orm.schema_validation.mode%',
        ];
    }

    /**
     * Unset, the schema is validated with warnings while developing, not in the tests nor in production.
     *
     * @param ManagerOptions $config
     */
    private static function schemaValidationMode(array $config, ?string $environment): string
    {
        return $config['schema_validation']['mode'] ?? ($environment === 'dev' ? 'warning' : 'disabled');
    }

    /**
     * @param ManagerOptions $config
     */
    private static function slowQueryThreshold(array $config): ?float
    {
        // A float node keeps an integer (500) as is.
        return $config['slow_query_threshold'] !== null ? (float) $config['slow_query_threshold'] : null;
    }

    /**
     * @param array{enabled: bool, pool: string, default_lifetime: int, regions: array<string, array{pool: string|null, lifetime: int|null}>} $secondLevel
     */
    private function configureCache(Definition $cache, ?string $resultPool, array $secondLevel): void
    {
        $cache->setArgument('$results', $resultPool !== null ? new Reference($resultPool) : null);

        if (!$secondLevel['enabled']) {
            return;
        }

        $cache->setArgument('$entities', new Reference($secondLevel['pool']));
        $cache->setArgument('$defaultLifetime', $secondLevel['default_lifetime']);
        $cache->setArgument('$regions', array_map(
            static fn (array $region) => ['pool' => new Reference($region['pool'] ?? $secondLevel['pool']), 'lifetime' => $region['lifetime'] ?? $secondLevel['default_lifetime']],
            $secondLevel['regions'],
        ));
    }

    /**
     * The pools of the caches of a manager, told to the profiler: their id and, at runtime, their adapter.
     *
     * @param ManagerOptions $config
     * @param string         $prefix the name of the manager when there are several
     *
     * @return array<string, array{id: string, pool: Reference, lifetime: int|null}>
     */
    private function cachePools(array $config, string $prefix): array
    {
        $caches = [];
        $resultPool = $config['result_cache']['pool'];
        $secondLevel = $config['second_level_cache'];

        if ($resultPool !== null) {
            $caches[$prefix . GraphqlCache::RESULT_CACHE] = ['id' => $resultPool, 'pool' => new Reference($resultPool), 'lifetime' => null];
        }

        if ($secondLevel['enabled']) {
            $caches[$prefix . GraphqlCache::DEFAULT_REGION] = ['id' => $secondLevel['pool'], 'pool' => new Reference($secondLevel['pool']), 'lifetime' => $secondLevel['default_lifetime']];

            foreach ($secondLevel['regions'] as $region => $options) {
                $pool = $options['pool'] ?? $secondLevel['pool'];
                $caches[$prefix . $region] = ['id' => $pool, 'pool' => new Reference($pool), 'lifetime' => $options['lifetime'] ?? $secondLevel['default_lifetime']];
            }
        }

        return $caches;
    }

    /**
     * The entities of the mapping directory of a manager.
     *
     * @param ManagerOptions $config
     *
     * @return class-string[]
     */
    private function entityClasses(ContainerBuilder $container, array $config): array
    {
        // The default directory holds %kernel.project_dir%: it is resolved before being read.
        $dir = $container->getParameterBag()->resolveValue($config['mapping']['entity']['dir']);

        return \is_string($dir) ? $this->scanEntityClasses($dir, $config['mapping']['entity']['namespace']) : [];
    }

    /**
     * @return class-string[]
     */
    private function scanEntityClasses(string $dir, string $namespace): array
    {
        $dir = rtrim($dir, '/');

        if (!is_dir($dir)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->in($dir)->name('*.php')->depth(0);

        $classes = [];

        foreach ($finder as $file) {
            $className = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            /** @var class-string $fqcn */
            $fqcn = rtrim($namespace, '\\') . '\\' . $className;
            $classes[] = $fqcn;
        }

        return $classes;
    }
}
