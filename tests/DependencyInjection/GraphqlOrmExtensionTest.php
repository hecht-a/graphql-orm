<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\DependencyInjection;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Command\FindCommand;
use GraphqlOrm\Command\MakeGraphqlEntityCommand;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\DependencyInjection\GraphqlOrmExtension;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Execution\IdentityMap;
use GraphqlOrm\Form\GraphqlEntityType;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\GraphqlManagerInterface;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\MetadataCacheWarmer;
use GraphqlOrm\Metadata\Naming\SnakeCaseNamingStrategy;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Schema\CountableFieldResolver;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use GraphqlOrm\Test\MockGraphqlServer;
use GraphqlOrm\Tests\Fixtures\Depth\ChainA;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityNamedByStrategy;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeProject;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeEventDispatcher;
use GraphqlOrm\Tests\Fixtures\FakeHeadersProvider;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeProjectRepository;
use GraphqlOrm\ValueResolver\GraphqlEntityValueResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GraphqlOrmExtensionTest extends TestCase
{
    private const SET_DEFAULTS = [
        'identity_map' => false,
        'max_depth' => 2,
        'variables' => ['enabled' => false],
        'schema_validation' => ['mode' => 'disabled'],
    ];

    #[Test]
    public function itFindsTheEntitiesOfADirectoryGivenWithTheProjectDir(): void
    {
        $container = $this->createContainer(debug: false, config: ['mapping' => ['entity' => [
            'dir' => '%kernel.project_dir%/tests/Fixtures/Depth',
            'namespace' => 'GraphqlOrm\\Tests\\Fixtures\\Depth',
        ]]]);

        $entityClasses = $container->getParameter('graphql_orm.schema_validation.entity_classes');

        self::assertIsArray($entityClasses);
        self::assertContains(ChainA::class, $entityClasses);
    }

    #[Test]
    public function itRegistersTheProfilerCollectorInDebugMode(): void
    {
        $container = $this->createContainer(debug: true);

        $container->compile();

        self::assertTrue($container->has(GraphqlOrmDataCollector::class));
    }

    #[Test]
    public function itDoesNotRegisterTheProfilerCollectorOutsideDebugMode(): void
    {
        $container = $this->createContainer(debug: false);

        $container->compile();

        self::assertFalse($container->has(GraphqlOrmDataCollector::class));
        self::assertInstanceOf(GraphqlManager::class, $container->get(GraphqlManager::class));
    }

    #[Test]
    public function itResolvesTheEntitiesOfTheControllersBeforeTheRequestAttributes(): void
    {
        $container = $this->createContainer(debug: false);

        $tags = $container->getDefinition(GraphqlEntityValueResolver::class)->getTag('controller.argument_value_resolver');

        self::assertSame([['priority' => 110, 'name' => GraphqlEntityValueResolver::class]], $tags);
    }

    #[Test]
    public function itRegistersTheFormTypeOfTheEntities(): void
    {
        $container = $this->createContainer(debug: false);

        self::assertTrue($container->getDefinition(GraphqlEntityType::class)->hasTag('form.type'));
    }

    #[Test]
    public function itSendsTheQueriesToTheMockServerOfTheTests(): void
    {
        $container = $this->createContainer(debug: false, config: ['http_client' => MockGraphqlServer::class, 'dialect' => DataApiBuilderDialect::class]);
        self::assertTrue($container->getDefinition(MockGraphqlServer::class)->isPublic());
        $container->compile();

        $server = $container->get(MockGraphqlServer::class);
        self::assertInstanceOf(MockGraphqlServer::class, $server);
        $server->respond(['tasks' => ['items' => [['id' => 1, 'title' => 'A']]]]);
        $manager = $container->get(GraphqlManager::class);
        self::assertInstanceOf(GraphqlManager::class, $manager);

        $task = (new GraphqlEntityRepository($manager, FakeTask::class))->find(1);

        self::assertSame('A', $task?->title);
        self::assertCount(1, $server->requests());
    }

    #[Test]
    public function itDoesNotRegisterTheMockServerOutsideTheTests(): void
    {
        $container = $this->createContainer(debug: false);

        self::assertFalse($container->hasDefinition(MockGraphqlServer::class));
    }

    #[Test]
    public function itNamesTheFieldsWithTheConfiguredStrategy(): void
    {
        $container = $this->createContainer(debug: false, config: ['naming_strategy' => SnakeCaseNamingStrategy::class]);
        $container->compile();

        $factory = $container->get(GraphqlEntityMetadataFactory::class);
        self::assertInstanceOf(GraphqlEntityMetadataFactory::class, $factory);

        self::assertSame('due_date', $factory->getMetadata(FakeEntityNamedByStrategy::class)->fields[2]->mappedFrom);
    }

    #[Test]
    public function itPassesTheSlowQueryThresholdToTheManagerAndTheProfiler(): void
    {
        $container = $this->createContainer(debug: true, config: ['slow_query_threshold' => 500]);

        self::assertSame(500.0, $container->getDefinition(GraphqlManager::class)->getArgument('$slowQueryThreshold'));
        self::assertSame(500.0, $container->getDefinition(GraphqlOrmDataCollector::class)->getArgument('$slowQueryThreshold'));
    }

    #[Test]
    public function itRegistersTheFindCommand(): void
    {
        $container = $this->createContainer(debug: false);
        $container->compile();

        self::assertInstanceOf(FindCommand::class, $container->get(FindCommand::class));
    }

    #[Test]
    public function itSharesAnIdentityMapResetBetweenRequests(): void
    {
        $container = $this->createContainer(debug: false, config: ['identity_map' => true]);

        self::assertTrue($container->getDefinition(IdentityMap::class)->hasTag('kernel.reset'));
        self::assertEquals(new Reference(IdentityMap::class), $container->getDefinition(GraphqlManager::class)->getArgument('$identityMap'));
    }

    #[Test]
    public function itSharesNoIdentityMapByDefault(): void
    {
        $container = $this->createContainer(debug: false);

        self::assertFalse($container->hasDefinition(IdentityMap::class));
    }

    #[Test]
    public function itGivesTheRepositoryServicesOfTheApplication(): void
    {
        $container = $this->createContainer(debug: false);
        $container->register(FakeProjectRepository::class)->setAutowired(true)->setAutoconfigured(true);
        $container->compile();

        $manager = $container->get(GraphqlManager::class);
        self::assertInstanceOf(GraphqlManager::class, $manager);

        self::assertInstanceOf(FakeProjectRepository::class, $manager->getRepository(FakeProject::class));
    }

    #[Test]
    public function itReportsTheOptionsWhoseDefaultValueChangesInVersion5(): void
    {
        $deprecations = self::deprecations(fn () => (new GraphqlOrmExtension())->load([['endpoint' => 'https://graphql.example.com/graphql']], self::kernelContainer()));

        self::assertSame([
            'Since hecht-a/graphql-orm 4.9: Not setting the "graphql_orm.identity_map" option is deprecated: its default value changes to true in 5.0, set it to keep false.',
            'Since hecht-a/graphql-orm 4.9: Not setting the "graphql_orm.max_depth" option is deprecated: its default value changes to 1 in 5.0, set it to keep 2.',
            'Since hecht-a/graphql-orm 4.9: Not setting the "graphql_orm.variables.enabled" option is deprecated: its default value changes to true in 5.0, set it to keep false.',
            'Since hecht-a/graphql-orm 4.9: Not setting the "graphql_orm.schema_validation.mode" option is deprecated: its default value changes to "warning" in debug mode in 5.0, set it to keep "disabled".',
        ], $deprecations);
    }

    #[Test]
    public function itReportsNothingOnceTheOptionsAreSet(): void
    {
        $configs = [
            ['endpoint' => 'https://graphql.example.com/graphql', 'identity_map' => true, 'variables' => ['enabled' => true]],
            ['max_depth' => 1, 'schema_validation' => ['mode' => 'warning']],
        ];

        self::assertSame([], self::deprecations(fn () => (new GraphqlOrmExtension())->load($configs, self::kernelContainer())));
    }

    /**
     * The deprecations triggered by a call, silenced as trigger_deprecation() does.
     *
     * @return list<string>
     */
    private static function deprecations(callable $call): array
    {
        $messages = [];
        set_error_handler(function (int $type, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $call();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }

    private static function kernelContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.project_dir', \dirname(__DIR__, 2));

        return $container;
    }

    #[Test]
    public function itAutowiresTheManagerByItsInterface(): void
    {
        $container = $this->createContainer(debug: false);

        self::assertSame(GraphqlManager::class, (string) $container->getAlias(GraphqlManagerInterface::class));
    }

    #[Test]
    public function itCompilesWithoutAFilesystemServiceFromTheFramework(): void
    {
        $container = $this->createContainer(debug: true);

        $container->compile();

        self::assertInstanceOf(MakeGraphqlEntityCommand::class, $container->get(MakeGraphqlEntityCommand::class));
    }

    #[Test]
    public function itUsesTheConfiguredHttpClientService(): void
    {
        $container = $this->createContainer(debug: false, config: ['http_client' => 'graphql.http_client']);
        $container->register('graphql.http_client', MockHttpClient::class);

        $container->compile();

        $client = $container->get(GraphqlClient::class);
        self::assertSame(
            $container->get('graphql.http_client'),
            (new \ReflectionProperty(GraphqlClient::class, 'httpClient'))->getValue($client),
        );
    }

    #[Test]
    public function itGivesTheDataApiBuilderDialectTheSchemaToCountWith(): void
    {
        $container = $this->createContainer(debug: false, config: ['dialect' => DataApiBuilderDialect::class]);

        $container->compile();

        $dialect = $container->get('graphql_orm.dialect');
        self::assertInstanceOf(DataApiBuilderDialect::class, $dialect);
        self::assertInstanceOf(CountableFieldResolver::class, (new \ReflectionProperty(DataApiBuilderDialect::class, 'countableFields'))->getValue($dialect));
    }

    #[Test]
    public function itDoesNotRegisterTheCountableFieldsForTheOtherDialects(): void
    {
        $container = $this->createContainer(debug: false);

        self::assertFalse($container->hasDefinition(CountableFieldResolver::class));
    }

    #[Test]
    public function itKeepsTheDashesOfTheHeaderNames(): void
    {
        $container = $this->createContainer(debug: false, config: ['headers' => ['X-API-Key' => 'secret', 'Authorization' => 'Bearer token']]);

        self::assertSame(['X-API-Key' => 'secret', 'Authorization' => 'Bearer token'], $container->getParameter('graphql_orm.headers'));
    }

    #[Test]
    public function itUsesTheConfiguredHeadersProvider(): void
    {
        $container = $this->createContainer(debug: false, config: ['headers_provider' => 'graphql.headers_provider']);
        $container->register('graphql.headers_provider', FakeHeadersProvider::class);

        $container->compile();

        $client = $container->get(GraphqlClient::class);
        self::assertSame(
            $container->get('graphql.headers_provider'),
            (new \ReflectionProperty(GraphqlClient::class, 'headersProvider'))->getValue($client),
        );
    }

    #[Test]
    public function itUsesTheDefaultHttpClientWhenNoneIsConfigured(): void
    {
        $container = $this->createContainer(debug: false);

        $container->compile();

        $client = $container->get(GraphqlClient::class);
        self::assertSame(
            $container->get(HttpClientInterface::class),
            (new \ReflectionProperty(GraphqlClient::class, 'httpClient'))->getValue($client),
        );
    }

    #[Test]
    public function itRedactsQueryValuesByDefault(): void
    {
        $container = $this->createContainer(debug: false);

        $container->compile();

        self::assertInstanceOf(QueryRedactor::class, $this->managerRedactor($container));
    }

    #[Test]
    public function itCanKeepQueryValuesInLogsAndProfiler(): void
    {
        $container = $this->createContainer(debug: true, config: ['redact_query_values' => false]);

        $container->compile();

        self::assertNull($this->managerRedactor($container));
    }

    #[Test]
    public function itCachesMetadataAndWarmsItUpOutsideDebugMode(): void
    {
        $container = $this->createContainer(debug: false, config: ['metadata_cache_pool' => 'cache.graphql']);
        $container->register('cache.graphql', ArrayAdapter::class);

        $container->compile();

        self::assertSame($container->get('cache.graphql'), $this->metadataFactoryCache($container));
        self::assertTrue($container->getDefinition(MetadataCacheWarmer::class)->hasTag('kernel.cache_warmer'));
    }

    #[Test]
    public function itDoesNotCacheMetadataInDebugMode(): void
    {
        $container = $this->createContainer(debug: true);

        $container->compile();

        self::assertNull($this->metadataFactoryCache($container));
        self::assertFalse($container->hasDefinition(MetadataCacheWarmer::class));
    }

    #[Test]
    public function itCanDisableTheMetadataCache(): void
    {
        $container = $this->createContainer(debug: false, config: ['metadata_cache_pool' => null]);

        $container->compile();

        self::assertNull($this->metadataFactoryCache($container));
        self::assertFalse($container->hasDefinition(MetadataCacheWarmer::class));
    }

    #[Test]
    public function itDispatchesQueryEventsThroughTheEventDispatcher(): void
    {
        $container = $this->createContainer(debug: false);
        $container->register('event_dispatcher', FakeEventDispatcher::class);

        $container->compile();

        self::assertSame(
            $container->get('event_dispatcher'),
            (new \ReflectionProperty(GraphqlManager::class, 'eventDispatcher'))->getValue($container->get(GraphqlManager::class)),
        );
    }

    #[Test]
    public function itDoesNotLoadRelationsLazilyByDefault(): void
    {
        $container = $this->createContainer(debug: false);

        $container->compile();

        self::assertFalse($this->managerLazyLoading($container));
    }

    #[Test]
    public function itCanLoadRelationsLazily(): void
    {
        $container = $this->createContainer(debug: false, config: ['lazy_loading' => true]);

        $container->compile();

        self::assertTrue($this->managerLazyLoading($container));
    }

    #[Test]
    public function itDoesNotUseVariablesByDefault(): void
    {
        $container = $this->createContainer(debug: false);

        $container->compile();

        self::assertNull($this->managerArgumentTypeResolver($container));
    }

    #[Test]
    public function itUsesVariablesWithTheConfiguredCachePool(): void
    {
        $container = $this->createContainer(debug: false, config: ['variables' => ['enabled' => true, 'cache_pool' => 'cache.graphql', 'ttl' => 600]]);
        $container->register('cache.graphql', ArrayAdapter::class);

        $container->compile();

        $resolver = $this->managerArgumentTypeResolver($container);
        self::assertInstanceOf(RootArgumentTypeResolver::class, $resolver);
        self::assertSame($container->get('cache.graphql'), (new \ReflectionProperty(RootArgumentTypeResolver::class, 'cache'))->getValue($resolver));
        self::assertSame(600, (new \ReflectionProperty(RootArgumentTypeResolver::class, 'ttl'))->getValue($resolver));
    }

    private function metadataFactoryCache(ContainerBuilder $container): mixed
    {
        return (new \ReflectionProperty(GraphqlEntityMetadataFactory::class, 'cache'))->getValue($container->get(GraphqlEntityMetadataFactory::class));
    }

    private function managerLazyLoading(ContainerBuilder $container): mixed
    {
        return (new \ReflectionProperty(GraphqlManager::class, 'lazyLoading'))->getValue($container->get(GraphqlManager::class));
    }

    private function managerArgumentTypeResolver(ContainerBuilder $container): mixed
    {
        return (new \ReflectionProperty(GraphqlManager::class, 'argumentTypeResolver'))->getValue($container->get(GraphqlManager::class));
    }

    private function managerRedactor(ContainerBuilder $container): mixed
    {
        return (new \ReflectionProperty(GraphqlManager::class, 'redactor'))->getValue($container->get(GraphqlManager::class));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createContainer(bool $debug, array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        $container->setParameter('kernel.project_dir', \dirname(__DIR__, 2));
        $container->register(HttpClientInterface::class, MockHttpClient::class);
        $container->register('cache.system', ArrayAdapter::class);
        $container->register('cache.app', ArrayAdapter::class);

        // The options whose default value changes in 5.0 are set, as their default is deprecated.
        (new GraphqlOrmExtension())->load([['endpoint' => 'https://graphql.example.com/graphql', ...self::SET_DEFAULTS, ...$config]], $container);

        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $definition) {
                    $definition->setPublic(true);
                }
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION);

        return $container;
    }
}
