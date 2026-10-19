<?php

declare(strict_types=1);

namespace GraphqlOrm\DependencyInjection\Node;

/**
 * Lets a numeric option be set to null, which turns off what it measures (an alert, a lifetime of its own...): the
 * nodes of Symfony refuse null once written, and compare it with their minimum.
 *
 * @internal
 */
trait AcceptsNull
{
    protected function validateType(mixed $value): void
    {
        if ($value !== null) {
            parent::validateType($value);
        }
    }

    protected function finalizeValue(mixed $value): mixed
    {
        return $value === null ? null : parent::finalizeValue($value);
    }
}
