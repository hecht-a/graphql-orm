<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

final readonly class CompiledQuery
{
    /**
     * @param array<string, mixed> $variables        values of the variables declared by the query
     * @param list<string>         $untypedArguments root arguments left inline because their type is unknown
     */
    public function __construct(
        public string $graphql,
        public array $variables = [],
        public array $untypedArguments = [],
    ) {
    }
}
