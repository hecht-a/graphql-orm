<?php

declare(strict_types=1);

namespace GraphqlOrm\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Finder\Finder;

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
