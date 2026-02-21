<?php

declare(strict_types=1);

namespace GraphqlOrm\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

final class GraphqlOrmExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();

        $config = $this->processConfiguration(
            $configuration,
            $configs
        );

        $loader = new PhpFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config')
        );

        $loader->load('services.php');

        $container->setParameter(
            'graphql_orm.endpoint',
            $config['endpoint']
        );

        $container->setParameter(
            'graphql_orm.headers',
            $config['headers']
        );

        $container->setParameter(
            'graphql_orm.max_depth',
            $config['max_depth']
        );
    }
}
