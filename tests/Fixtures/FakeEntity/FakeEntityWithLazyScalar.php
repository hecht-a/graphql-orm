<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Fetch;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

#[Entity(name: 'items')]
class FakeEntityWithLazyScalar
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Relation(mappedFrom: 'title', fetch: Fetch::Lazy)]
    public string $title;
}
