<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Entity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

/**
 * Identified by a text, which Data API Builder cannot count on.
 */
#[Entity(name: 'labels')]
class Label
{
    #[Field]
    #[Identifier]
    public string $code;

    #[Field]
    public string $name;

    #[Field]
    public int $position;
}
