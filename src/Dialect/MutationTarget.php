<?php

declare(strict_types=1);

namespace GraphqlOrm\Dialect;

/**
 * The entity a mutation writes, as a MutationDialect names its mutations: its root query field (tasks), its GraphQL
 * type (Task) and its identifier field (id).
 */
final readonly class MutationTarget
{
    public function __construct(
        public string $root,
        public string $type,
        public string $identifierField,
    ) {
    }
}
