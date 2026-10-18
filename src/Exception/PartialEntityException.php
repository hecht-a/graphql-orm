<?php

declare(strict_types=1);

namespace GraphqlOrm\Exception;

/**
 * A property of an entity read although the query hydrating it did not select it.
 */
final class PartialEntityException extends \LogicException implements GraphqlOrmException
{
    /**
     * @param list<string> $properties the properties left uninitialized
     */
    public static function forProperties(string $class, array $properties): self
    {
        return new self(\sprintf(
            '"%s" was hydrated without %s, not selected by the query: select it with the query builder, raise max_depth for a relation, or map the relation with GraphqlOrm\Fetch::Lazy to load it on access.',
            $class,
            implode(', ', $properties),
        ));
    }
}
