<?php

declare(strict_types=1);

namespace GraphqlOrm\Attribute;

/**
 * A lifecycle callback of an entity, as Doctrine #[PrePersist]: a public method without parameter. Called by persist() on a new entity, before flush() creates it: what it sets is written.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class PrePersist
{
}
