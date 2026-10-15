<?php

declare(strict_types=1);

namespace GraphqlOrm\Mapping;

use GraphqlOrm\Repository\GraphqlEntityRepository;

/**
 * Maps a class on the GraphQL root field of its entities, as Doctrine #[Entity].
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Entity
{
    /**
     * @param string $name the GraphQL root query field (e.g. tasks)
     * @param class-string<GraphqlEntityRepository<*>>|null $repositoryClass the repository service of the entity
     */
    public function __construct(
        public string $name,
        public ?string $repositoryClass = null,
    ) {
    }
}
