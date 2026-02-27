<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata;

final readonly class GraphqlFieldMetadata
{
    public function __construct(
        public string $property,
        public string $mappedFrom,
        /** @var class-string|null */
        public ?string $relation = null,
        public bool $isCollection = false,
        public bool $isIdentifier = false,
        public bool $ignoreValidation = false,
    ) {
    }
}
