<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Embedded;

use GraphqlOrm\Mapping\Embedded;
use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

#[Entity(name: 'orders')]
class OrderWithNotEmbeddable
{
    #[Field]
    #[Identifier]
    public int $id;

    #[Embedded]
    public ?NotEmbeddable $price = null;
}
