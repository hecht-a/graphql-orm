<?php

declare(strict_types=1);

namespace GraphqlOrm\Codegen;

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
    ) {
    }
}
