<?php

declare(strict_types=1);

namespace GraphqlOrm\Exception;

final class InvalidGraphqlResponseException extends \UnexpectedValueException
{
    public static function expectedArray(mixed $value): self
    {
        return new self(\sprintf(
            'Invalid GraphQL response: expected array, got "%s".',
            get_debug_type($value)
        ));
    }
}
