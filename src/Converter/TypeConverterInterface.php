<?php

declare(strict_types=1);

namespace GraphqlOrm\Converter;

/**
 * Converts a scalar of the API into a value of PHP, a value object such as Money, Email or Siret, and back: chosen for
 * the properties typed with the class of its #[AsTypeConverter], or named by #[GraphqlOrm\Field(converter: ...)]. null
 * is never given to it: it stays null.
 */
interface TypeConverterInterface
{
    /**
     * The value of a response, as the property or the parameter of a DTO receives it.
     */
    public function toPhp(mixed $value): mixed;

    /**
     * The value sent to the API, in a filter or a criterion: a scalar, or a list or an input object of them.
     */
    public function toGraphql(mixed $value): mixed;
}
