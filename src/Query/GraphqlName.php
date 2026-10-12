<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

/**
 * GraphQL Name grammar: https://spec.graphql.org/October2021/#Name.
 */
final class GraphqlName
{
    private const PATTERN = '/^[_A-Za-z][_0-9A-Za-z]*$/';

    public static function isValid(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }
}
