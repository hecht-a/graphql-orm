<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

#[GraphqlEntity(name: 'logs')]
class FakeEntityWithoutIdentifier
{
    #[GraphqlField(mappedFrom: 'message')]
    public string $message;
}
