<?php

declare(strict_types=1);

use Doctrine\Persistence\Event\LifecycleEventArgs;
use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\Codegen\StubRenderer;
use GraphqlOrm\Command\DebugEntityCommand;
use GraphqlOrm\Command\EntityClassLocator;
use GraphqlOrm\Command\FindCommand;
use GraphqlOrm\Command\MakeGraphqlEntityCommand;
use GraphqlOrm\Command\SchemaValidateCommand;
use GraphqlOrm\Controller\EntityAutocompleteController;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Doctrine\GraphqlReferenceListener;
use GraphqlOrm\EventListener\SchemaValidationListener;
use GraphqlOrm\Form\GraphqlEntityType;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\GraphqlManagerInterface;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\Naming\DefaultNamingStrategy;
use GraphqlOrm\Metadata\Naming\SnakeCaseNamingStrategy;
use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Schema\SchemaValidationMode;
use GraphqlOrm\Schema\SchemaValidator;
use GraphqlOrm\Serializer\GraphqlEntityNormalizer;
use GraphqlOrm\Twig\GraphqlOrmTwigExtension;
use GraphqlOrm\ValueResolver\GraphqlEntityValueResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Twig\Extension\AbstractExtension;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

return static function (ContainerConfigurator $config) {
    $services = $config->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(GraphqlEntityMetadataFactory::class);
    $services->set(DefaultNamingStrategy::class);
    $services->set(SnakeCaseNamingStrategy::class);
    $services->set(EntityHydrator::class);

    $services
        ->set('graphql_orm.dialect', '%graphql_orm.dialect%')
        ->autowire()
        ->autoconfigure();

    $services->set(GraphqlManager::class)
        ->arg('$collector', service(GraphqlOrmDataCollector::class)->nullOnInvalid())
        ->arg('$maxDepth', '%graphql_orm.max_depth%')
        ->arg('$dialect', service('graphql_orm.dialect'))
        ->arg('$logger', service('monolog.logger.graphql_orm')->nullOnInvalid())
        ->arg('$redactor', service(QueryRedactor::class))
        ->arg('$eventDispatcher', service('event_dispatcher')->nullOnInvalid())
        ->arg('$repositories', tagged_locator('graphql_orm.repository'));

    $services->alias(GraphqlManagerInterface::class, GraphqlManager::class);

    $services->set(QueryRedactor::class);

    // Before the request attribute resolver (priority 100), which would give the route parameter as is.
    $services->set(GraphqlEntityValueResolver::class)
        ->autoconfigure(false)
        ->tag('controller.argument_value_resolver', ['priority' => 110, 'name' => GraphqlEntityValueResolver::class]);

    // twig/twig is optional: the profiler panel needs it, and highlights the queries with this extension.
    if (class_exists(AbstractExtension::class)) {
        $services->set(GraphqlOrmTwigExtension::class)
            ->tag('twig.extension');
    }

    // symfony/serializer is optional: before the ObjectNormalizer, so that a lazy relation is not loaded to be written.
    if (interface_exists(NormalizerInterface::class)) {
        $services->set(GraphqlEntityNormalizer::class)
            ->autoconfigure(false)
            ->arg('$collector', service(GraphqlOrmDataCollector::class)->nullOnInvalid())
            ->tag('serializer.normalizer', ['priority' => 100]);
    }

    // doctrine/persistence is optional: the Doctrine entities referencing GraphQL entities (#[GraphqlReference]).
    if (class_exists(LifecycleEventArgs::class)) {
        $services->set(GraphqlReferenceListener::class)
            ->tag('doctrine.event_listener', ['event' => 'postLoad'])
            ->tag('doctrine.event_listener', ['event' => 'prePersist']);
    }

    // symfony/form is optional.
    if (interface_exists(FormTypeInterface::class)) {
        $services->set(GraphqlEntityType::class)
            ->autoconfigure(false)
            ->arg('$uriSigner', service('uri_signer')->nullOnInvalid())
            ->tag('form.type');

        $services->set(EntityAutocompleteController::class)
            ->autoconfigure(false)
            ->arg('$uriSigner', service('uri_signer')->nullOnInvalid())
            ->arg('$collector', service(GraphqlOrmDataCollector::class)->nullOnInvalid())
            ->tag('controller.service_arguments')
            ->public();
    }

    $services->set(GraphqlClientInterface::class);

    $services->set(GraphqlClient::class)
        ->arg('$endpoint', '%graphql_orm.endpoint%')
        ->arg('$headers', '%graphql_orm.headers%')
        ->arg('$httpClientOptions', '%graphql_orm.http_client_options%')
        ->arg('$projectDir', '%kernel.project_dir%')
        ->arg('$stopwatch', service(Stopwatch::class)->nullOnInvalid());

    $services->alias(GraphqlClientInterface::class, GraphqlClient::class);

    $services->set(GraphqlOrmDataCollector::class)
        ->arg('$entityClasses', param('graphql_orm.schema_validation.entity_classes'))
        ->tag('data_collector', [
            'id' => 'graphql_orm',
            'template' => '@GraphqlOrm/collector/graphql_orm.html.twig',
        ])
        ->public();

    $services->set(StubRenderer::class)
        ->arg('$stubsDir', __DIR__ . '/../stubs');

    $services->set('graphql_orm.filesystem', Filesystem::class);

    $services->set(MakeGraphqlEntityCommand::class)
        ->arg('$fs', service('graphql_orm.filesystem'))
        ->tag('console.command');

    $services->set(DebugEntityCommand::class)
        ->tag('console.command');

    $services->set(SchemaValidateCommand::class)
        ->tag('console.command');

    $services->set(EntityClassLocator::class);

    $services->set(FindCommand::class)
        ->tag('console.command');

    $services->set(SchemaIntrospector::class)
        ->arg('$collector', service(GraphqlOrmDataCollector::class)->nullOnInvalid());
    $services->set(SchemaValidator::class);

    $services->set('graphql_orm.schema_validation_mode')
        ->class(SchemaValidationMode::class)
        ->factory([SchemaValidationMode::class, 'from'])
        ->arg(0, param('graphql_orm.schema_validation.mode'));

    $services->set(SchemaValidationListener::class)
        ->arg('$collector', service(GraphqlOrmDataCollector::class)->nullOnInvalid())
        ->arg('$mode', service('graphql_orm.schema_validation_mode'))
        ->arg('$entityClasses', param('graphql_orm.schema_validation.entity_classes'));
};
