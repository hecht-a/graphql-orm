<?php

declare(strict_types=1);

namespace GraphqlOrm\PHPStan;

use GraphqlOrm\Query\GraphqlQueryBuilder;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * Checks the paths given to select() / addSelect() and the field given to orderBy() on a query builder.
 *
 * @implements Rule<MethodCall>
 *
 * @internal
 */
final readonly class QueryBuilderFieldNamesRule implements Rule
{
    public function __construct(
        private FieldNameChecker $checker,
    ) {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $method = $node->name->toString();

        if (!\in_array($method, ['select', 'addSelect', 'orderBy'], true)) {
            return [];
        }

        $errors = [];

        foreach (EntityClassResolver::entityClasses($node, $scope, GraphqlQueryBuilder::class) as $entityClass) {
            foreach ($method === 'select' ? $node->getArgs() : \array_slice($node->getArgs(), 0, 1) as $arg) {
                if ($arg->unpack) {
                    continue;
                }

                foreach (EntityClassResolver::constantStrings($arg, $scope) as $field) {
                    $errors = [...$errors, ...($method === 'orderBy'
                        ? $this->checker->checkSentField($entityClass, $field)
                        : $this->checker->checkSelectedPath($entityClass, $field))];
                }
            }
        }

        return $errors;
    }
}
