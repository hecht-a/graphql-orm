<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Cache;

use GraphqlOrm\Mapping\Cache;
use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

#[Entity(name: 'labels')]
#[Cache(region: 'unknown')]
class CachedInUnknownRegion
{
    #[Field]
    #[Identifier]
    public string $code;
}
