<?php

declare(strict_types=1);

namespace GraphqlOrm\Exception;

final class NotAGraphqlEntityException extends \LogicException implements GraphqlOrmException
{
    public static function forClass(string $class): self
    {
        return new self(\sprintf(
            'Class "%s" is not marked with #[GraphqlEntity].',
            $class
        ));
    }
}
