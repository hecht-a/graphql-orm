<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Crm;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;

/**
 * A relation to an entity of another API, which the CRM cannot select.
 */
#[Entity(name: 'contacts')]
class ContactWithTask
{
    #[Field]
    #[Identifier]
    public int $id;

    #[Relation]
    public ?FakeTask $task = null;
}
