<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Query\Aggregate;
use GraphqlOrm\Query\Ast\FieldNode;

/**
 * A dialect able to aggregate a field of the entities matching some arguments, for the sum(), avg(), min() and max()
 * methods of GraphqlEntityRepository. The aggregate field is printed as is: it has no collection envelope.
 */
interface AggregatingDialect
{
    /**
     * The root field selecting the aggregate of a field over the entities matching the arguments.
     *
     * @param string               $root      the root query field of the entity
     * @param array<string, mixed> $arguments the arguments built by applyCriteria()
     * @param string               $field     the GraphQL name of the aggregated field
     */
    public function aggregateField(string $root, array $arguments, Aggregate $aggregate, string $field): FieldNode;

    /**
     * @param array<mixed> $data          the `data` of the response
     * @param string       $aggregateRoot the name of the field returned by aggregateField()
     *
     * @return int|float|string|null null when no entity matches
     *
     * @throws InvalidGraphqlResponseException when the response holds no aggregate
     */
    public function readAggregate(array $data, string $aggregateRoot, Aggregate $aggregate, string $field): int|float|string|null;
}
