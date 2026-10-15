<?php

declare(strict_types=1);

namespace GraphqlOrm;

use GraphqlOrm\Repository\GraphqlEntityRepository;

/**
 * The manager as the applications use it, as Doctrine EntityManagerInterface: the type to inject, and to simulate or
 * decorate in their tests.
 */
interface GraphqlManagerInterface
{
    /**
     * The repository of an entity: the service of the repositoryClass of its #[Entity] when the application declares
     * one, a generic repository otherwise.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return GraphqlEntityRepository<T>
     */
    public function getRepository(string $class): GraphqlEntityRepository;

    /**
     * Forgets the entities of the shared identity map, as between two requests: for a long-running script.
     */
    public function clear(): void;
}
