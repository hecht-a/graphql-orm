<?php

declare(strict_types=1);

namespace GraphqlOrm\Mapping;

/**
 * Maps a field holding a value: a scalar, a date, an enum, as Doctrine #[Column]. A relation is mapped with
 * #[Relation], the identifier marked with #[Identifier].
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Field
{
    public function __construct(
        /** GraphQL name of the field; named by the naming strategy from the property when null */
        public ?string $mappedFrom = null,
        public bool $ignoreValidation = false,
    ) {
    }
}
