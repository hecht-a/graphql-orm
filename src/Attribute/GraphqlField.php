<?php

declare(strict_types=1);

namespace GraphqlOrm\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class GraphqlField
{
    public function __construct(
        /** GraphQL name of the field; named by the naming strategy from the property when null */
        public ?string $mappedFrom = null,
        /** @var class-string|null */
        public ?string $targetEntity = null,
        public bool $identifier = false,
        public bool $ignoreValidation = false,
    ) {
    }
}
