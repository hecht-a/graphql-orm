<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

/**
 * Each property has the GraphQL name of the other field.
 */
#[GraphqlEntity(name: 'items')]
class FakeEntityWithSwappedNames
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'label')]
    public string $name;

    #[GraphqlField(mappedFrom: 'name')]
    public string $label;
}
