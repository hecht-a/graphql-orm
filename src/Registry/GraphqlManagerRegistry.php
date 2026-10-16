<?php

declare(strict_types=1);

namespace GraphqlOrm\Registry;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The managers of the GraphQL APIs of the application, as the ManagerRegistry of Doctrine: by name, or the manager of an
 * entity.
 */
final readonly class GraphqlManagerRegistry
{
    /**
     * @param ContainerInterface $managers the managers by name
     */
    public function __construct(
        private ContainerInterface $managers,
        private ManagerRouter $router,
    ) {
    }

    /**
     * The registry of a single manager, every entity being its own: for a script or a test building the services by hand.
     */
    public static function forManager(GraphqlManager $manager): self
    {
        return new self(
            new ServiceLocator([$manager->getName() => static fn (): GraphqlManager => $manager]),
            new ManagerRouter([$manager->getName() => ''], $manager->getName()),
        );
    }

    public function getDefaultManagerName(): string
    {
        return $this->router->defaultManager();
    }

    /**
     * @return list<string>
     */
    public function getManagerNames(): array
    {
        return $this->router->managerNames();
    }

    /**
     * @param string|null $name the default manager when null
     *
     * @throws InvalidArgumentException when no manager has this name
     */
    public function getManager(?string $name = null): GraphqlManager
    {
        $name ??= $this->getDefaultManagerName();

        if (!$this->managers->has($name)) {
            throw new InvalidArgumentException(\sprintf('The GraphQL ORM manager "%s" does not exist: %s.', $name, implode(', ', $this->getManagerNames())));
        }

        $manager = $this->managers->get($name);
        \assert($manager instanceof GraphqlManager);

        return $manager;
    }

    /**
     * The manager of the API of an entity: the one its #[Entity] names, or whose entity namespace holds it, or the
     * default one.
     *
     * @param class-string $class
     *
     * @throws InvalidArgumentException when the #[Entity] of the class names a manager that does not exist
     */
    public function getManagerForClass(string $class): GraphqlManager
    {
        return $this->getManager($this->router->managerFor($class));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return GraphqlEntityRepository<T>
     */
    public function getRepository(string $class): GraphqlEntityRepository
    {
        return $this->getManagerForClass($class)->getRepository($class);
    }
}
