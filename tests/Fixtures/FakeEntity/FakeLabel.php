<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

/**
 * An entity whose identifier is a string, which Data API Builder cannot count.
 */
#[Entity(name: 'labels')]
class FakeLabel
{
    #[Field]
    #[Identifier]
    public string $code;

    #[Field]
    public int $position;
}
