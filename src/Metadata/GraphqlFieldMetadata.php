<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata;

use GraphqlOrm\Mapping\Fetch;

/**
 * @internal
 */
final readonly class GraphqlFieldMetadata
{
    public function __construct(
        public string $property,
        public string $mappedFrom,
        /** @var class-string|null */
        public ?string $relation = null,
        public bool $isCollection = false,
        public bool $isIdentifier = false,
        public bool $ignoreValidation = false,
        public Fetch $fetch = Fetch::Eager,
        /** @var class-string|null the type converter named by #[GraphqlOrm\Field(converter: ...)] */
        public ?string $converter = null,
        /** @var class-string|null the class the property is typed with, whose type converter applies */
        public ?string $valueClass = null,
        /**
         * The value object of an #[GraphqlOrm\Embedded] property, whose response key is $mappedFrom once its fields are nested.
         */
        public ?GraphqlEmbeddedMetadata $embedded = null,
        /** The GraphQL field of the mutations holding the identifier of the related entity, e.g. user_id */
        public ?string $joinField = null,
    ) {
    }
}
