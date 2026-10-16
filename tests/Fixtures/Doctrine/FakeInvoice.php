<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Doctrine;

use GraphqlOrm\Doctrine\GraphqlReference;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeLabel;

/**
 * A Doctrine entity (its ORM mapping left out) holding the code of a label of the GraphQL API.
 */
class FakeInvoice
{
    public ?string $labelCode = null;

    #[GraphqlReference(identifier: 'labelCode')]
    public ?FakeLabel $label = null;
}
