<?php

declare(strict_types=1);

namespace GraphqlOrm\Attribute;

/**
 * A lifecycle callback of an entity, as Doctrine #[PostPersist]: a public method without parameter. Called once flush() created the entity: its identifier is set.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class PostPersist
{
}
