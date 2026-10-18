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
     * @param string|null $manager the manager of its API, when not the one whose
     *                             entity namespace holds it
     * @param string|null $type    the GraphQL type of the entity (e.g. Task), naming its
     *                             mutations (createTask); the short name of the class when null
     */
    public function __construct(
        public string $name,
        public ?string $repositoryClass = null,
        public ?string $manager = null,
        public ?string $type = null,
    ) {
    }
}
