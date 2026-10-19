<?php

declare(strict_types=1);

namespace GraphqlOrm\Command;

use GraphqlOrm\Registry\GraphqlManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The entities kept in the second-level cache, by manager: those of the cache commands.
 *
 * @internal
 */
final readonly class CachedEntities
{
    /**
     * @param array<string, class-string[]> $managerEntityClasses the entities of each manager, by name
     */
    public function __construct(
        private GraphqlManagerRegistry $managers,
        private EntityClassLocator $entityClassLocator,
        #[Autowire('%graphql_orm.manager_entity_classes%')]
        private array $managerEntityClasses = [],
    ) {
    }

    /**
     * The managers named, or every manager.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException when no manager has this name
     */
    public function managerNames(?string $manager): array
    {
        return $manager !== null ? [$this->managers->getManager($manager)->getName()] : $this->managers->getManagerNames();
    }

    /**
     * An entity named on the command line, kept in the second-level cache of its manager.
     *
     * @return array{string, class-string} its manager and its class
     *
     * @throws \InvalidArgumentException when the entity is unknown, of another manager, or not kept in the cache
     */
    public function entity(string $entity, ?string $manager): array
    {
        $class = $this->entityClassLocator->resolve($entity)
            ?? throw new \InvalidArgumentException($this->entityClassLocator->notFound($entity));
        $name = $this->managers->getManagerForClass($class)->getName();

        if ($manager !== null && $this->managers->getManager($manager)->getName() !== $name) {
            throw new \InvalidArgumentException(\sprintf('"%s" belongs to the manager "%s", not "%s".', $class, $name, $manager));
        }

        $cache = $this->managers->getManager($name)->getCache();

        if (!$cache->keepsEntities()) {
            throw new \InvalidArgumentException(\sprintf('The second-level cache of the manager "%s" is disabled: enable graphql_orm.second_level_cache.', $name));
        }

        if (!$cache->caches($class)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not kept in the second-level cache: mark it with #[GraphqlOrm\Cache].', $class));
        }

        return [$name, $class];
    }

    /**
     * Every entity marked #[GraphqlOrm\Cache] of the managers named, or of every manager.
     *
     * @return list<array{string, class-string}> the manager and the class of each one
     *
     * @throws \InvalidArgumentException when no manager has this name
     */
    public function all(?string $manager): array
    {
        $entities = [];

        foreach ($this->managerNames($manager) as $name) {
            $cache = $this->managers->getManager($name)->getCache();

            foreach ($this->managerEntityClasses[$name] ?? [] as $class) {
                if ($cache->caches($class)) {
                    $entities[] = [$name, $class];
                }
            }
        }

        return $entities;
    }
}
