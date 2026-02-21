<?php

declare(strict_types=1);

namespace GraphqlOrm\Attribute;

use GraphqlOrm\Repository\GraphqlEntityRepository;

/**
 * @template T of object
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class GraphqlEntity
{
    /**
     * @param class-string<GraphqlEntityRepository<T>>|null $repositoryClass
     */
    public function __construct(
        public string $name,
        public ?string $repositoryClass = null,
    ) {
    }
}
