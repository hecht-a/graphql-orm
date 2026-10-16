<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Doctrine;

use GraphqlOrm\Doctrine\GraphqlReference;
use GraphqlOrm\Tests\Fixtures\Crm\Contact;

/**
 * A Doctrine entity (its ORM mapping left out) holding the identifier of a contact of the CRM, a second GraphQL API.
 */
class FakeOrder
{
    public ?int $contactId = null;

    #[GraphqlReference(identifier: 'contactId')]
    public ?Contact $contact = null;
}
