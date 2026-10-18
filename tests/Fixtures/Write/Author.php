<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Write;

use GraphqlOrm\Mapping as GraphqlOrm;

#[GraphqlOrm\Entity(name: 'authors')]
class Author
{
    #[GraphqlOrm\Field]
    #[GraphqlOrm\Identifier]
    public ?int $id = null;

    #[GraphqlOrm\Field]
    public string $name;
}
