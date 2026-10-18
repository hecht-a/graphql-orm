<?php

declare(strict_types=1);

namespace GraphqlOrm\Attribute;

/**
 * A lifecycle callback of an entity, as Doctrine #[PreRemove]: a public method without parameter. Called by remove() on an entity a query read, before flush() deletes it.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class PreRemove
{
}
