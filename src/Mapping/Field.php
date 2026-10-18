<?php

declare(strict_types=1);

namespace GraphqlOrm\Mapping;

use GraphqlOrm\Converter\TypeConverterInterface;

/**
 * Maps a field holding a value: a scalar, a date, an enum, as Doctrine #[Column]. A relation is mapped with
 * #[GraphqlOrm\Relation], the identifier marked with #[GraphqlOrm\Identifier].
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Field
{
    public function __construct(
        /** GraphQL name of the field; named by the naming strategy from the property when null */
        public ?string $mappedFrom = null,
        public bool $ignoreValidation = false,
        /**
         * The type converter of the value, rather than the one of the type of the property.
         *
         * @var class-string<TypeConverterInterface>|null
         */
        public ?string $converter = null,
    ) {
    }
}
