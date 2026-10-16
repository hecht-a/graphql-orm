<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\DependencyInjection;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Command\FindCommand;
use GraphqlOrm\Command\MakeGraphqlEntityCommand;
use GraphqlOrm\Controller\EntityAutocompleteController;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\DependencyInjection\GraphqlOrmExtension;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Doctrine\GraphqlReferenceListener;
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
use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Serializer\GraphqlEntityNormalizer;
use GraphqlOrm\Test\MockGraphqlServer;
use GraphqlOrm\Tests\Fixtures\Depth\ChainA;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityNamedByStrategy;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeProject;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeEventDispatcher;
use GraphqlOrm\Tests\Fixtures\FakeHeadersProvider;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeProjectRepository;
use GraphqlOrm\Twig\GraphqlOrmTwigExtension;
use GraphqlOrm\ValueResolver\GraphqlEntityValueResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GraphqlOrmExtensionTest extends TestCase
{
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
    public function itSharesAnIdentityMapByDefault(): void
    {
        $container = $this->createContainer(debug: false);

        self::assertTrue($container->hasDefinition(IdentityMap::class));
    }

    #[Test]
    public function itCanKeepEveryQueryApart(): void
    {
        $container = $this->createContainer(debug: false, config: ['identity_map' => false]);

        self::assertFalse($container->hasDefinition(IdentityMap::class));
    }

    #[Test]
    public function itSelectsTheRelationsOnOneLevelByDefault(): void
    {
        $container = $this->createContainer(debug: false);

        self::assertSame(1, $container->getParameterBag()->resolveValue($container->getDefinition(GraphqlManager::class)->getArgument('$maxDepth')));
    }

    #[Test]
    public function itValidatesTheSchemaWithWarningsInTheDevEnvironmentByDefault(): void
    {
        self::assertSame('warning', $this->createContainer(debug: true, environment: 'dev')->getParameter('graphql_orm.schema_validation.mode'));
        self::assertSame('disabled', $this->createContainer(debug: true, environment: 'test')->getParameter('graphql_orm.schema_validation.mode'));
        self::assertSame('disabled', $this->createContainer(debug: false, environment: 'prod')->getParameter('graphql_orm.schema_validation.mode'));
        self::assertSame('exception', $this->createContainer(debug: true, config: ['schema_validation' => ['mode' => 'exception']], environment: 'dev')->getParameter('graphql_orm.schema_validation.mode'));
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
    public function itRecordsTheIntrospectionQueriesInTheProfilerInDebugMode(): void
    {
        $container = $this->createContainer(debug: true, config: ['dialect' => DataApiBuilderDialect::class]);

        $container->compile();

        foreach ([RootArgumentTypeResolver::class, CountableFieldResolver::class, SchemaIntrospector::class] as $class) {
            self::assertInstanceOf(GraphqlOrmDataCollector::class, (new \ReflectionProperty($class, 'collector'))->getValue($container->get($class)), $class);
        }
    }

    #[Test]
    public function itShowsTheConfigurationInTheProfilerWithoutItsSecrets(): void
    {
        $container = $this->createContainer(debug: true, config: [
            'endpoint' => 'https://user:secret@graphql.example.com/graphql',
            'headers' => ['X-API-Key' => 'secret'],
        ]);

        $configuration = $container->getDefinition(GraphqlOrmDataCollector::class)->getArgument('$configuration');

        self::assertIsArray($configuration);
        self::assertSame('https://graphql.example.com/graphql', $configuration['endpoint'] ?? null);
        self::assertSame(['X-API-Key'], $configuration['headers'] ?? null);
        self::assertSame(1, $configuration['max_depth'] ?? null);
        self::assertStringNotContainsString('secret', (string) json_encode($configuration));
    }

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
    public function itRegistersTheTwigExtensionOfTheProfiler(): void
    {
        $container = $this->createContainer(debug: true);

        self::assertTrue($container->getDefinition(GraphqlOrmTwigExtension::class)->hasTag('twig.extension'));
    }

    #[Test]
    public function itGivesTheProfilerTheEntitiesOfTheMappingDirectory(): void
    {
        $container = $this->createContainer(debug: true, config: ['mapping' => ['entity' => [
            'dir' => '%kernel.project_dir%/tests/Fixtures/Depth',
            'namespace' => 'GraphqlOrm\\Tests\\Fixtures\\Depth',
        ]]]);

        $container->compile();
        $collector = $container->get(GraphqlOrmDataCollector::class);
        self::assertInstanceOf(GraphqlOrmDataCollector::class, $collector);
        $collector->collect(new Request(), new Response());

        self::assertContains(ChainA::class, array_column($collector->getEntities(), 'class'));
    }

    #[Test]
    public function itRegistersTheNormalizerOfTheEntitiesBeforeTheObjectNormalizer(): void
    {
        $container = $this->createContainer(debug: false);

        self::assertSame([['priority' => 100]], $container->getDefinition(GraphqlEntityNormalizer::class)->getTag('serializer.normalizer'));
    }

    #[Test]
    public function itListensToTheDoctrineEntitiesReferencingGraphqlEntities(): void
    {
        $container = $this->createContainer(debug: false);

        self::assertSame(
            [['event' => 'postLoad'], ['event' => 'prePersist']],
            $container->getDefinition(GraphqlReferenceListener::class)->getTag('doctrine.event_listener'),
        );
    }

    #[Test]
    public function itRegistersTheSearchOfTheAutocompleteFields(): void
    {
        $container = $this->createContainer(debug: false);

        $definition = $container->getDefinition(EntityAutocompleteController::class);

        self::assertTrue($definition->isPublic());
        self::assertTrue($definition->hasTag('controller.service_arguments'));
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
    public function itUsesVariablesByDefault(): void
    {
        $container = $this->createContainer(debug: false);

        $container->compile();

        self::assertInstanceOf(RootArgumentTypeResolver::class, $this->managerArgumentTypeResolver($container));
    }

    #[Test]
    public function itCanSendTheValuesInline(): void
    {
        $container = $this->createContainer(debug: false, config: ['variables' => ['enabled' => false]]);

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
    private function createContainer(bool $debug, array $config = [], string $environment = 'test'): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        $container->setParameter('kernel.project_dir', \dirname(__DIR__, 2));
        $container->register(HttpClientInterface::class, MockHttpClient::class);
        $container->register('cache.system', ArrayAdapter::class);
        $container->register('cache.app', ArrayAdapter::class);
        $container->setParameter('kernel.environment', $environment);

        (new GraphqlOrmExtension())->load([['endpoint' => 'https://graphql.example.com/graphql', ...$config]], $container);

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
