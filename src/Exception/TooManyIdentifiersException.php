<?php

declare(strict_types=1);

namespace GraphqlOrm\Exception;

final class TooManyIdentifiersException extends \LogicException implements GraphqlOrmException
{
    public static function forClass(string $class): self
    {
        return new self(\sprintf(
            'Entity "%s" has multiple identifiers.',
            $class
        ));
    }
}
