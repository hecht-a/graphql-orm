<?php

declare(strict_types=1);

namespace GraphqlOrm\Repository;

use GraphqlOrm\Registry\GraphqlManagerRegistry;

/**
 * A repository service of the application taking the manager of the API of its entity from the registry, as the
 * ServiceEntityRepository of Doctrine: it works whatever the manager of the entity.
 *
 * @template T of object
 *
 * @extends GraphqlEntityRepository<T>
 */
class ServiceGraphqlEntityRepository extends GraphqlEntityRepository
{
    /**
     * @param class-string<T> $entityClass
     */
    public function __construct(GraphqlManagerRegistry $registry, string $entityClass)
    {
        parent::__construct($registry->getManagerForClass($entityClass), $entityClass);
    }
}
