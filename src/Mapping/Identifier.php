<?php

declare(strict_types=1);

namespace GraphqlOrm\Mapping;

/**
 * Marks the field identifying the entity, declared with #[GraphqlOrm\Field], as Doctrine #[Id].
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Identifier
{
}
