<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures;

use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;

/**
 * Returns the given entities in turn instead of hydrating, the last one being repeated, and counts the calls.
 */
final class FakeEntityHydrator extends EntityHydrator
{
    public int $callCount = 0;

    /** @var list<object> */
    private array $entities;

    public function __construct(object ...$entities)
    {
        parent::__construct(new FakeMetadataFactory());
        $this->entities = array_values($entities);
    }

    public function hydrate(GraphqlEntityMetadata $metadata, array $data, GraphqlExecutionContext $context, int $depth = 0): object
    {
        $entity = $this->entities[$this->callCount] ?? end($this->entities);
        ++$this->callCount;

        return $entity !== false ? $entity : new \stdClass();
    }
}
