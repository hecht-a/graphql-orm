<?php

declare(strict_types=1);

namespace GraphqlOrm\Attribute;

/**
 * Marks the field identifying the entity, declared with #[GraphqlField], as Doctrine #[Id].
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Identifier
{
}
