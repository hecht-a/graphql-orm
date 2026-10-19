<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Write;

use GraphqlOrm\Mapping as GraphqlOrm;

/**
 * Related to a category of its own: two new categories may need the identifiers of each other.
 */
#[GraphqlOrm\Entity(name: 'categories')]
class Category
{
    #[GraphqlOrm\Field]
    #[GraphqlOrm\Identifier]
    public ?int $id = null;

    #[GraphqlOrm\Field]
    public string $name;

    #[GraphqlOrm\Relation(joinField: 'related_id')]
    public ?Category $related = null;
}
