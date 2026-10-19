<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Php84;

use GraphqlOrm\Mapping as GraphqlOrm;
use GraphqlOrm\Mapping\Fetch;

#[GraphqlOrm\Entity(name: 'comments')]
class Comment
{
    #[GraphqlOrm\Field]
    #[GraphqlOrm\Identifier]
    public readonly int $id;

    #[GraphqlOrm\Relation(fetch: Fetch::Lazy)]
    public ?Note $note = null;
}
