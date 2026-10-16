<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Crm;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

/**
 * An entity of a second API, the CRM: its namespace is the entity namespace of the manager "crm".
 */
#[Entity(name: 'contacts')]
class Contact
{
    #[Field]
    #[Identifier]
    public int $id;

    #[Field]
    public string $name;
}
