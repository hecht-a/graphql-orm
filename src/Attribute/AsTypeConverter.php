<?php

declare(strict_types=1);

namespace GraphqlOrm\Attribute;

/**
 * The class of the values a type converter handles: the properties and the parameters of DTOs typed with it are
 * converted by it, unless their #[Field] names another converter.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class AsTypeConverter
{
    public function __construct(
        /** @var class-string */
        public string $class,
    ) {
    }
}
