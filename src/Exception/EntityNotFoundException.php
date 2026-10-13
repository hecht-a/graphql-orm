<?php

declare(strict_types=1);

namespace GraphqlOrm\Exception;

final class EntityNotFoundException extends \RuntimeException implements GraphqlOrmException
{
    public static function forIdentifier(string $class, int|string $identifier): self
    {
        return new self(\sprintf('Entity "%s" with identifier "%s" was not found: it cannot be lazy loaded.', $class, $identifier));
    }
}
