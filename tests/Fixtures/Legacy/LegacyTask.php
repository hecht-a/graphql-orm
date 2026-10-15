<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Legacy;

use GraphqlOrm\Attribute\Fetch;
use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Tests\Fixtures\Mapping\MappedUser;

/**
 * The mapping of 4.8 and before, deprecated in 4.9: identifier and relations through #[GraphqlField].
 */
#[GraphqlEntity(name: 'tasks')]
class LegacyTask
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'user', fetch: Fetch::Lazy)]
    public ?MappedUser $user = null;
}
