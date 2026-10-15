<?php

declare(strict_types=1);

namespace GraphqlOrm\Attribute;

use GraphqlOrm\Mapping\Fetch;

/**
 * @deprecated since 4.9, use #[GraphqlOrm\Mapping\Field], #[GraphqlOrm\Mapping\Identifier] and #[GraphqlOrm\Mapping\Relation]
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class GraphqlField
{
    public function __construct(
        /** GraphQL name of the field; named by the naming strategy from the property when null */
        public ?string $mappedFrom = null,
        /**
         * @deprecated since 4.9, map a relation with #[Relation]
         *
         * @var class-string|null
         */
        public ?string $targetEntity = null,
        /** @deprecated since 4.9, add #[Identifier] */
        public bool $identifier = false,
        public bool $ignoreValidation = false,
        /** @deprecated since 4.9, map a relation with #[Relation] */
        public Fetch $fetch = Fetch::Eager,
    ) {
    }
}
