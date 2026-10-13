<?php

declare(strict_types=1);

namespace GraphqlOrm\PHPStan;

use GraphqlOrm\Repository\GraphqlEntityRepository;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * Checks the criteria and ordering keys of the repository find and count methods, which are sent as is to the API.
 *
 * @implements Rule<MethodCall>
 *
 * @internal
 */
final readonly class RepositoryFieldNamesRule implements Rule
{
    private const FIND_METHODS = ['findBy', 'findOneBy', 'count'];

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
        if (!$node->name instanceof Identifier || !\in_array($node->name->toString(), self::FIND_METHODS, true)) {
            return [];
        }

        $entityClasses = EntityClassResolver::entityClasses($node, $scope, GraphqlEntityRepository::class);
        $fields = [
            ...EntityClassResolver::constantKeys(EntityClassResolver::argument($node, 0, 'criteria'), $scope),
            ...EntityClassResolver::constantKeys(EntityClassResolver::argument($node, 1, 'orderBy'), $scope),
        ];

        $errors = [];

        foreach ($entityClasses as $entityClass) {
            foreach ($fields as $field) {
                $errors = [...$errors, ...$this->checker->checkSentField($entityClass, $field)];
            }
        }

        return $errors;
    }
}
