<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata\Naming;

use function Symfony\Component\String\u;

/**
 * The GraphQL name is the property name in snake case, as databases exposed by Hasura or Data API Builder name their
 * columns: `dueDate` is `due_date`.
 */
final class SnakeCaseNamingStrategy implements NamingStrategy
{
    public function fieldName(string $property): string
    {
        return u($property)->snake()->toString();
    }
}
