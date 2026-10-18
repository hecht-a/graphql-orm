<?php

declare(strict_types=1);

namespace GraphqlOrm\Attribute;

/**
 * A lifecycle callback of an entity, as Doctrine #[PostRemove]: a public method without parameter. Called once flush() deleted the entity.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class PostRemove
{
}
