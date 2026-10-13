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
    public function __construct(
        #[Autowire('%graphql_orm.mapping.entity.dir%')]
        private string $entityDir,
        #[Autowire('%graphql_orm.mapping.entity.namespace%')]
        private string $entityNamespace,
    ) {
    }

    /**
     * @return class-string|null
     */
    public function resolve(string $entity): ?string
    {
        if (class_exists($entity)) {
            return $entity;
        }

        $fqcn = rtrim($this->entityNamespace, '\\') . '\\' . ltrim($entity, '\\');

        return class_exists($fqcn) ? $fqcn : null;
    }

    /**
     * @return list<string> the short names of the entities of the entity directory
     */
    public function available(): array
    {
        if (!is_dir($this->entityDir)) {
            return [];
        }

        $entities = [];

        foreach ((new Finder())->files()->in($this->entityDir)->name('*.php')->depth(0) as $file) {
            $entities[] = pathinfo($file->getFilename(), PATHINFO_FILENAME);
        }

        sort($entities);

        return $entities;
    }
}
