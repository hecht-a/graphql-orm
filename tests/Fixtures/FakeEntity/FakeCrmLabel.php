<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

/**
 * Among the entities of the default API, but read from the CRM: its #[Entity] names the manager.
 */
#[Entity(name: 'labels', manager: 'crm')]
class FakeCrmLabel
{
    #[Field]
    #[Identifier]
    public string $code;
}
