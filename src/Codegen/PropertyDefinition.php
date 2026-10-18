<?php

declare(strict_types=1);

namespace GraphqlOrm\Codegen;

/**
 * @internal
 */
final class PropertyDefinition
{
    public function __construct(
        public string $name,
        public string $phpType,
        public string $mappedFrom,
        public bool $nullable,
        public bool $identifier = false,
        public bool $relation = false,
        public bool $collection = false,
        public ?string $targetEntity = null,
        /** @var class-string|null the value object of a custom scalar, imported by the entity */
        public ?string $valueClass = null,
    ) {
    }
}
