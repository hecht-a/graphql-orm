<?php

declare(strict_types=1);

namespace GraphqlOrm\Mapping;

/**
 * A property holding an #[GraphqlOrm\Embeddable] value object, as Doctrine #[Embedded]: by default a GraphQL object
 * (`price { amount currency }`); with a prefix, fields of the entity itself (`total_amount`, `total_currency`).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Embedded
{
    public function __construct(
        /** GraphQL name of the object; named by the naming strategy from the property when null. Not used with a prefix */
        public ?string $mappedFrom = null,
        /** The prefix of the fields of the entity holding the value object, e.g. 'total_'; a GraphQL object when null */
        public ?string $prefix = null,
    ) {
    }
}
