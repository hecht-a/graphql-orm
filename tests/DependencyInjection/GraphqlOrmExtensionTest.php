<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\DependencyInjection;

use GraphqlOrm\Command\MakeGraphqlEntityCommand;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\DependencyInjection\GraphqlOrmExtension;
use GraphqlOrm\GraphqlManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
        // Arrange
        $container = $this->createContainer(debug: true);

        // Act
        $container->compile();

        // Assert
        self::assertTrue($container->has(GraphqlOrmDataCollector::class));
    }

    #[Test]
    public function itDoesNotRegisterTheProfilerCollectorOutsideDebugMode(): void
    {
        // Arrange
        $container = $this->createContainer(debug: false);

        // Act
        $container->compile();

        // Assert
        self::assertFalse($container->has(GraphqlOrmDataCollector::class));
        self::assertInstanceOf(GraphqlManager::class, $container->get(GraphqlManager::class));
    }

    #[Test]
    public function itCompilesWithoutAFilesystemServiceFromTheFramework(): void
    {
        // Arrange
        $container = $this->createContainer(debug: true);

        // Act
        $container->compile();

        // Assert
        self::assertInstanceOf(MakeGraphqlEntityCommand::class, $container->get(MakeGraphqlEntityCommand::class));
    }

    private function createContainer(bool $debug): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        $container->setParameter('kernel.project_dir', \dirname(__DIR__, 2));
        $container->register(HttpClientInterface::class, MockHttpClient::class);

        (new GraphqlOrmExtension())->load([['endpoint' => 'https://graphql.example.com/graphql']], $container);

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
