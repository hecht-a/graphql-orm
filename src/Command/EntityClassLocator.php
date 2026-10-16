<?php

declare(strict_types=1);

namespace GraphqlOrm\Command;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

/**
 * Finds an entity class from the name given to a command: its short name in the entity namespace, or its FQCN.
 *
 * @internal
 */
final readonly class EntityClassLocator
{
    /** @var list<array{dir: string, namespace: string}> */
    private array $mappings;

    /**
     * @param list<array{dir: string, namespace: string}> $mappings the entity mappings of every manager; the given
     *                                                              directory and namespace when empty
     */
    public function __construct(
        #[Autowire('%graphql_orm.mapping.entity.dir%')]
        string $entityDir,
        #[Autowire('%graphql_orm.mapping.entity.namespace%')]
        string $entityNamespace,
        #[Autowire('%graphql_orm.entity_mappings%')]
        array $mappings = [],
    ) {
        $this->mappings = $mappings !== [] ? $mappings : [['dir' => $entityDir, 'namespace' => $entityNamespace]];
    }

    /**
     * @return class-string|null
     */
    public function resolve(string $entity): ?string
    {
        if (class_exists($entity)) {
            return $entity;
        }

        foreach ($this->mappings as $mapping) {
            $fqcn = rtrim($mapping['namespace'], '\\') . '\\' . ltrim($entity, '\\');

            if ($mapping['namespace'] !== '' && class_exists($fqcn)) {
                return $fqcn;
            }
        }

        return null;
    }

    /**
     * @return list<string> the short names of the entities of the entity directories
     */
    public function available(): array
    {
        $entities = [];

        foreach ($this->mappings as $mapping) {
            if (!is_dir($mapping['dir'])) {
                continue;
            }

            foreach ((new Finder())->files()->in($mapping['dir'])->name('*.php')->depth(0) as $file) {
                $entities[] = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            }
        }

        $entities = array_values(array_unique($entities));
        sort($entities);

        return $entities;
    }
}
