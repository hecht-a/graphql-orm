<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Ast;

final class FieldNode
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments = [],
        public ?SelectionSetNode $selectionSet = null,
        public bool $isCollection = false,
    ) {
    }
}
