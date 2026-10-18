<?php

declare(strict_types=1);

namespace GraphqlOrm\Persistence;

/**
 * The identifier of an entity created by the same flush, known once the API created it.
 *
 * @internal
 */
final readonly class PendingIdentifier
{
    public function __construct(
        public object $entity,
    ) {
    }
}
