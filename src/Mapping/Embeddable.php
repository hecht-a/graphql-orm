<?php

declare(strict_types=1);

namespace GraphqlOrm\Mapping;

/**
 * A value object spread over several fields of the API, as Doctrine #[Embeddable]: its fields are mapped with
 * #[GraphqlOrm\Field], and it is created through its constructor, their values given by name. It is held by a property
 * mapped with #[GraphqlOrm\Embedded].
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Embeddable
{
}
