<?php

declare(strict_types=1);

namespace GraphqlOrm\Registry;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Mapping\Entity;

/**
 * Tells the manager of an entity: the one its #[GraphqlOrm\Entity] names, or the one whose entity namespace holds it
 * (the most precise when they nest), or the default manager.
 *
 * @internal
 */
final class ManagerRouter
{
    /** @var array<string, string> by class */
    private array $managers = [];

    /**
     * @param array<string, string> $namespaces the entity namespace of each manager, by name
     */
    public function __construct(
        private readonly array $namespaces,
        private readonly string $defaultManager,
    ) {
    }

    public function defaultManager(): string
    {
        return $this->defaultManager;
    }

    /**
     * @return list<string>
     */
    public function managerNames(): array
    {
        return array_keys($this->namespaces);
    }

    /**
     * @throws InvalidArgumentException when the #[GraphqlOrm\Entity] of the class names a manager that does not exist
     */
    public function managerFor(string $class): string
    {
        return $this->managers[$class] ??= $this->resolve($class);
    }

    private function resolve(string $class): string
    {
        $named = class_exists($class) ? ((new \ReflectionClass($class))->getAttributes(Entity::class)[0] ?? null)?->newInstance()->manager : null;

        if ($named !== null) {
            return isset($this->namespaces[$named])
                ? $named
                : throw new InvalidArgumentException(\sprintf('"%s" names the GraphQL ORM manager "%s" in its #[GraphqlOrm\Entity], which is not one of: %s.', $class, $named, implode(', ', $this->managerNames())));
        }

        $manager = $this->defaultManager;
        $longest = 0;

        foreach ($this->namespaces as $name => $namespace) {
            $prefix = rtrim($namespace, '\\') . '\\';

            if (str_starts_with($class, $prefix) && \strlen($prefix) > $longest) {
                $manager = $name;
                $longest = \strlen($prefix);
            }
        }

        return $manager;
    }
}
