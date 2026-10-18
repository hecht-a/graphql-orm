<?php

declare(strict_types=1);

namespace GraphqlOrm\Attribute;

/**
 * A lifecycle callback of an entity, as Doctrine #[PreUpdate]: a public method without parameter. Called by flush() before updating an entity changed since a query read it: what it sets is written.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class PreUpdate
{
}
