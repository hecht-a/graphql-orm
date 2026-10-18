<?php

declare(strict_types=1);

namespace GraphqlOrm\Attribute;

/**
 * The class of the values a type converter handles: the properties and the parameters of DTOs typed with it are
 * converted by it, unless their #[GraphqlOrm\Field] names another converter.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class AsTypeConverter
{
    public function __construct(
        /** @var class-string */
        public string $class,
        /**
         * The GraphQL scalars the converter reads and writes, e.g. ['Int']: the schema validation checks the fields it
         * converts use one of them. Any scalar when empty.
         *
         * @var list<string>
         */
        public array $scalars = [],
    ) {
    }
}
