<?php

declare(strict_types=1);

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\Codegen\StubRenderer;
use GraphqlOrm\Command\MakeGraphqlEntityCommand;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $config) {
    $services = $config->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(GraphqlEntityMetadataFactory::class);
    $services->set(EntityHydrator::class);

    $services
        ->set('graphql_orm.dialect', '%graphql_orm.dialect%')
        ->autowire()
        ->autoconfigure();

    $services->set(GraphqlManager::class)
        ->arg('$maxDepth', '%graphql_orm.max_depth%')
        ->arg('$dialect', service('graphql_orm.dialect'));

    $services->set(GraphqlClientInterface::class);

    $services->set(GraphqlClient::class)
        ->arg('$endpoint', '%graphql_orm.endpoint%')
        ->arg('$headers', '%graphql_orm.headers%');

    $services->alias(GraphqlClientInterface::class, GraphqlClient::class);

    $services->set(GraphqlOrmDataCollector::class)
        ->tag('data_collector', [
            'id' => 'graphql_orm',
            'template' => '@GraphqlOrm/collector/graphql_orm.html.twig',
        ])
        ->public();

    $services->set(StubRenderer::class)
        ->arg('$stubsDir', __DIR__ . '/../stubs');

    $services->set(MakeGraphqlEntityCommand::class)
        ->tag('console.command');
};
