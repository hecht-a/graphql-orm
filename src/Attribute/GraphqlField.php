<?php

declare(strict_types=1);

namespace GraphqlOrm\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class GraphqlField
{
    public function __construct(
        public string $mappedFrom,
        /** @var class-string|null */
        public ?string $targetEntity = null,
        public bool $identifier = false,
        public bool $ignoreValidation = false,
    ) {
    }
}
