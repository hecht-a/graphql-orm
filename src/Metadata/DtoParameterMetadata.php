<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata;

/**
 * A parameter of the constructor of a DTO and the field it maps: a value, or a relation hydrated as a nested DTO or a
 * list of them.
 *
 * @internal
 */
final readonly class DtoParameterMetadata
{
    /**
     * @param list<string> $path the GraphQL names leading to the field from the entity queried
     */
    public function __construct(
        public string $name,
        public array $path,
        public ?\ReflectionType $type,
        public bool $allowsNull,
        public bool $hasDefault,
        public ?DtoMetadata $nested = null,
        public bool $isCollection = false,
    ) {
    }
}
