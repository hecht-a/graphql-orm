<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Ast;

final class QueryNode
{
    public string $operation = 'query';

    /** @var FieldNode[] */
    public array $fields = [];
}
