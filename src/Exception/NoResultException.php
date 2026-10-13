<?php

declare(strict_types=1);

namespace GraphqlOrm\Exception;

final class NoResultException extends \RuntimeException implements GraphqlOrmException
{
    /**
     * @param class-string $class
     */
    public static function forClass(string $class): self
    {
        return new self(\sprintf('The query returned no "%s" where exactly one was expected.', $class));
    }
}
