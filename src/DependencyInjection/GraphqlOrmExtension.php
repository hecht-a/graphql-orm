<?php

declare(strict_types=1);

namespace GraphqlOrm\DependencyInjection;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Execution\IdentityMap;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\MetadataCacheWarmer;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use GraphqlOrm\Test\MockGraphqlServer;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Finder\Finder;

/**
 * @internal
 */
final class GraphqlOrmExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();

        /**
         * @var array{
         *   endpoint: string,
         *   http_client_options: array{verify_host: bool, verify_peer: bool},
         *   redact_query_values: bool,
         *   variables: array{enabled: bool, cache_pool: string, ttl: int},
         *   http_client: string|null,
         *   dialect: string,
         *   headers: array<string, string>,
         *   metadata_cache_pool: string|null,
         *   naming_strategy: string,
         *   slow_query_threshold: int|float|null,
         *   identity_map: bool,
         *   headers_provider: string|null,
         *   lazy_loading: bool,
         *   max_depth: int,
         *   schema_validation: array{mode: string},
         *   mapping: array{
         *     entity: array{dir: string, namespace: string},
         *     repository: array{dir: string, namespace: string}
         *   }
         * } $config
         */
        $config = $this->processConfiguration(
            $configuration,
            $configs
        );

        $loader = new PhpFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config')
        );

        $loader->load('services.php');

        // The repositories of the application, given by GraphqlManager::getRepository().
        $container->registerForAutoconfiguration(GraphqlEntityRepository::class)->addTag('graphql_orm.repository');

        // The mock server of the tests is fetched by them from the container.
        if ($config['http_client'] === MockGraphqlServer::class) {
            $container->register(MockGraphqlServer::class, MockGraphqlServer::class)->setPublic(true);
        }

        if ($config['http_client'] !== null) {
            $container->getDefinition(GraphqlClient::class)->setArgument('$httpClient', new Reference($config['http_client']));
        }

        if ($config['headers_provider'] !== null) {
            $container->getDefinition(GraphqlClient::class)->setArgument('$headersProvider', new Reference($config['headers_provider']));
        }

        if ($config['variables']['enabled']) {
            $container->register(RootArgumentTypeResolver::class, RootArgumentTypeResolver::class)
                ->setArguments([
                    new Reference(GraphqlClientInterface::class),
                    new Reference($config['variables']['cache_pool']),
                    $config['variables']['ttl'],
                    new Reference('monolog.logger.graphql_orm', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ]);

            $container->getDefinition(GraphqlManager::class)
                ->setArgument('$argumentTypeResolver', new Reference(RootArgumentTypeResolver::class));
        }

        if ($config['lazy_loading']) {
            $container->getDefinition(GraphqlManager::class)->setArgument('$lazyLoading', true);
        }

        if (!$config['redact_query_values']) {
            $container->getDefinition(GraphqlManager::class)->setArgument('$redactor', null);
        }

        $debug = $container->hasParameter('kernel.debug') && $container->getParameter('kernel.debug');

        if ($config['identity_map']) {
            $container->register(IdentityMap::class, IdentityMap::class)->addTag('kernel.reset', ['method' => 'reset']);
            $container->getDefinition(GraphqlManager::class)->setArgument('$identityMap', new Reference(IdentityMap::class));
        }

        // A float node keeps an integer (500) as is.
        $slowQueryThreshold = $config['slow_query_threshold'] !== null ? (float) $config['slow_query_threshold'] : null;
        $container->getDefinition(GraphqlManager::class)->setArgument('$slowQueryThreshold', $slowQueryThreshold);

        // The collector keeps every query in memory: only useful when the profiler can display it.
        if (!$debug) {
            $container->removeDefinition(GraphqlOrmDataCollector::class);
        } else {
            $container->getDefinition(GraphqlOrmDataCollector::class)->setArgument('$slowQueryThreshold', $slowQueryThreshold);
        }

        $container->getDefinition(GraphqlEntityMetadataFactory::class)->setArgument('$namingStrategy', new Reference($config['naming_strategy']));

        if (!$debug && $config['metadata_cache_pool'] !== null) {
            $container->getDefinition(GraphqlEntityMetadataFactory::class)->setArgument('$cache', new Reference($config['metadata_cache_pool']));
            $container->register(MetadataCacheWarmer::class, MetadataCacheWarmer::class)
                ->setArguments([new Reference(GraphqlEntityMetadataFactory::class), '%graphql_orm.schema_validation.entity_classes%'])
                ->addTag('kernel.cache_warmer');
        }

        $container->setParameter('graphql_orm.endpoint', $config['endpoint']);
        $container->setParameter('graphql_orm.http_client_options', $config['http_client_options']);
        $container->setParameter('graphql_orm.dialect', $config['dialect']);
        $container->setParameter('graphql_orm.headers', $config['headers']);
        $container->setParameter('graphql_orm.max_depth', $config['max_depth']);

        $container->setParameter('graphql_orm.schema_validation.mode', $config['schema_validation']['mode']);

        $entityDir = $config['mapping']['entity']['dir'];
        $entityNamespace = $config['mapping']['entity']['namespace'];
        $container->setParameter('graphql_orm.schema_validation.entity_classes', $this->scanEntityClasses($entityDir, $entityNamespace));

        $container->setParameter('graphql_orm.mapping.entity.dir', $entityDir);
        $container->setParameter('graphql_orm.mapping.entity.namespace', $entityNamespace);
        $container->setParameter('graphql_orm.mapping.repository.dir', $config['mapping']['repository']['dir']);
        $container->setParameter('graphql_orm.mapping.repository.namespace', $config['mapping']['repository']['namespace']);
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
