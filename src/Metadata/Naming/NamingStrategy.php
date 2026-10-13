<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata\Naming;

/**
 * Gives the GraphQL name of a field whose #[GraphqlField] has no "mappedFrom", from its PHP property.
 */
interface NamingStrategy
{
    public function fieldName(string $property): string;
}
