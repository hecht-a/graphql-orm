<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;

#[Entity(name: 'logs')]
class FakeEntityWithoutIdentifier
{
    #[Field(mappedFrom: 'message')]
    public string $message;
}
