<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use Doctrine\Common\Collections\Collection;
use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

/**
 * A collection typed with doctrine/collections, its target read from the generic type.
 */
#[Entity(name: 'users')]
class FakeUserWithTaskCollection
{
    #[Field]
    #[Identifier]
    public int $id;

    /** @var Collection<int, FakeTask> */
    #[Relation]
    public Collection $tasks;
}
