<?php

declare(strict_types=1);

namespace GraphqlOrm\Mapping;

/**
 * Maps a relation to another entity, or a collection of them, as Doctrine #[ManyToOne] and #[OneToMany].
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Relation
{
    public function __construct(
        /** GraphQL name of the field; named by the naming strategy from the property when null */
        public ?string $mappedFrom = null,
        /**
         * The related entity, when the type of the property does not tell it (object, or an array without docblock).
         *
         * @var class-string|null
         */
        public ?string $targetEntity = null,
        /** When the relation is loaded: Fetch::Lazy selects it by its identifier and loads it on first access */
        public Fetch $fetch = Fetch::Eager,
        public bool $ignoreValidation = false,
    ) {
    }
}
