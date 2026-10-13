<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata\Naming;

/**
 * The GraphQL name is the property name: `dueDate`.
 */
final class DefaultNamingStrategy implements NamingStrategy
{
    public function fieldName(string $property): string
    {
        return $property;
    }
}
