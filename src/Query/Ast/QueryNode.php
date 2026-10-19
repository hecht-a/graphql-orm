<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Ast;

final class QueryNode
{
    public string $operation = 'query';

    /** The name of the operation, told to the logs of the server; anonymous when null. */
    public ?string $name = null;

    /** @var FieldNode[] */
    public array $fields = [];
}
