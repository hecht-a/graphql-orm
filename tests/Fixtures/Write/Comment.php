<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Write;

use GraphqlOrm\Mapping as GraphqlOrm;

/**
 * A relation without join field: flush() cannot write it.
 */
#[GraphqlOrm\Entity(name: 'comments')]
class Comment
{
    #[GraphqlOrm\Field]
    #[GraphqlOrm\Identifier]
    public ?int $id = null;

    #[GraphqlOrm\Field]
    public string $body;

    #[GraphqlOrm\Relation]
    public ?Author $author = null;
}
