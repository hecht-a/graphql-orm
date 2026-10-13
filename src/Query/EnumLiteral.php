<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Exception\InvalidArgumentException;

/**
 * A GraphQL enum value built at runtime (e.g. `TITLE_DESC`), printed without quotes in a query and sent as a string in
 * the variables. Its name is validated, so that it cannot break out of the generated query.
 */
final readonly class EnumLiteral
{
    /**
     * @throws InvalidArgumentException when the name is not a valid GraphQL name
     */
    public function __construct(
        public string $name,
    ) {
        if (!GraphqlName::isValid($name)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a valid GraphQL enum value.', $name));
        }
    }
}
