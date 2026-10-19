<?php

declare(strict_types=1);

namespace GraphqlOrm\Exception;

final class InvalidGraphqlResponseException extends \UnexpectedValueException implements GraphqlOrmException
{
    public static function expectedArray(mixed $value): self
    {
        return new self(\sprintf(
            'Invalid GraphQL response: expected array, got "%s".',
            get_debug_type($value)
        ));
    }

    /**
     * @param string $what what the number is, e.g. `the count of "tasks"`
     */
    public static function expectedNumber(mixed $value, string $what): self
    {
        return new self(\sprintf('Invalid GraphQL response: expected a number for %s, got "%s".', $what, get_debug_type($value)));
    }
}
