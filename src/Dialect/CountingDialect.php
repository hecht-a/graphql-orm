<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Query\Ast\FieldNode;

/**
 * A dialect able to count the entities matching some arguments, for GraphqlEntityRepository::count(). The count field
 * is printed as is: it has no collection envelope.
 */
interface CountingDialect
{
    /**
     * The root field selecting the number of entities matching the arguments.
     *
     * @param string               $root            the root query field of the entity
     * @param array<string, mixed> $arguments       the arguments built by applyCriteria()
     * @param string|null          $identifierField the GraphQL name of the identifier; null when the entity has none, or
     *                                              since 4.10 when its property is typed with neither int nor float
     */
    public function countField(string $root, array $arguments, ?string $identifierField): FieldNode;

    /**
     * @param array<mixed> $data      the `data` of the response
     * @param string       $countRoot the name of the field returned by countField()
     *
     * @throws InvalidGraphqlResponseException when the response holds no count
     */
    public function readCount(array $data, string $countRoot): int;
}
