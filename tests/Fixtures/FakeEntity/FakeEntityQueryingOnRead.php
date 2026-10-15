<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;

/**
 * An entity sending a query when read, as a relation loaded on first access does from __toString() or a getter.
 */
#[Entity(name: 'things')]
class FakeEntityQueryingOnRead
{
    #[Field]
    #[Identifier]
    public int $id = 1;

    public function __construct(
        private readonly GraphqlClientInterface $client,
        public readonly GraphqlExecutionContext $context = new GraphqlExecutionContext(),
    ) {
    }

    public function __toString(): string
    {
        $this->client->query('query { things { id } }', $this->context);

        return 'thing';
    }
}
