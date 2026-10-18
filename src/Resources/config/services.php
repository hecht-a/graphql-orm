<?php

declare(strict_types=1);

use Doctrine\Persistence\Event\LifecycleEventArgs;
use GraphqlOrm\Codegen\StubRenderer;
use GraphqlOrm\Command\CacheClearCommand;
use GraphqlOrm\Command\CachedEntities;
use GraphqlOrm\Command\CacheStatsCommand;
use GraphqlOrm\Command\CacheWarmupCommand;
use GraphqlOrm\Command\DebugEntityCommand;
use GraphqlOrm\Command\EntityClassLocator;
use GraphqlOrm\Command\FindCommand;
use GraphqlOrm\Command\MakeGraphqlEntityCommand;
use GraphqlOrm\Command\PhpstormMetaCommand;
use GraphqlOrm\Command\QueryCommand;
use GraphqlOrm\Command\SchemaValidateCommand;
use GraphqlOrm\Controller\EntityAutocompleteController;
use GraphqlOrm\Converter\TypeConverterRegistry;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\DependencyInjection\GraphqlOrmExtension;
use GraphqlOrm\Doctrine\GraphqlReferenceListener;
use GraphqlOrm\Form\GraphqlEntityType;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Metadata\Naming\DefaultNamingStrategy;
use GraphqlOrm\Metadata\Naming\SnakeCaseNamingStrategy;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use GraphqlOrm\Serializer\GraphqlEntityNormalizer;
use GraphqlOrm\Twig\GraphqlOrmTwigExtension;
use GraphqlOrm\ValueResolver\GraphqlEntityValueResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Twig\Extension\AbstractExtension;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $config) {
    $services = $config->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // The services of each manager (client, dialect, metadata, hydrator, caches, schema validation) are registered by
    // GraphqlOrmExtension, those of the default manager aliased by their class.
    $services->set(DefaultNamingStrategy::class);
    $services->set(SnakeCaseNamingStrategy::class);

    $services->set(QueryRedactor::class);

    // The type converters of the application, autoconfigured: shared by every manager.
    $services->set(TypeConverterRegistry::class)
        ->arg('$converters', tagged_iterator(GraphqlOrmExtension::TYPE_CONVERTER_TAG));

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
            ->arg('$managers', service(GraphqlManagerRegistry::class))
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

    $services->set(QueryCommand::class)
        ->tag('console.command');

    $services->set(CachedEntities::class);

    $services->set(CacheWarmupCommand::class)
        ->tag('console.command');

    $services->set(CacheClearCommand::class)
        ->tag('console.command');

    $services->set(PhpstormMetaCommand::class)
        ->tag('console.command');

    // Its $cachePools are given by GraphqlOrmExtension, a CachePoolDescriber per manager.
    $services->set(CacheStatsCommand::class)
        ->tag('console.command');
};
