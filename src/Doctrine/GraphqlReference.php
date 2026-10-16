<?php

declare(strict_types=1);

namespace GraphqlOrm\Doctrine;

/**
 * On a property of a Doctrine entity, not mapped by Doctrine: the entity of the GraphQL API whose identifier is held by
 * another property, a mapped column. The property is set on load with a reference loaded on first access.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class GraphqlReference
{
    /**
     * @param string            $identifier   the property holding the identifier, a column of the Doctrine entity
     * @param class-string|null $targetEntity the GraphQL entity, read from the type of the property when null
     */
    public function __construct(
        public string $identifier,
        public ?string $targetEntity = null,
    ) {
    }
}
