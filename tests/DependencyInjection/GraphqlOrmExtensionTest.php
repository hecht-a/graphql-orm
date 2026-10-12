<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\DependencyInjection;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Command\MakeGraphqlEntityCommand;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\DependencyInjection\GraphqlOrmExtension;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use GraphqlOrm\Tests\Fixtures\FakeHeadersProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;
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
