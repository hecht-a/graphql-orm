<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;

/**
 * The relations named by with(), as a tree of GraphQL names: `assignee.company` gives ['assignee' => ['company' => []]].
 *
 * @internal
 */
final readonly class RelationTree
{
    public function __construct(
        private GraphqlEntityMetadataFactory $metadataFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $tree      the relations named before
     * @param class-string         $class     the entity the paths start from
     * @param list<string>         $relations paths of GraphQL field names or PHP properties
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when a name is not a relation of its entity
     */
    public function add(array $tree, string $class, array $relations): array
    {
        foreach ($relations as $path) {
            $tree = $this->addPath($tree, $class, explode('.', $path));
        }

        return $tree;
    }

    /**
     * @param array<string, mixed> $tree
     * @param class-string         $class
     * @param string[]             $names
     *
     * @return array<string, mixed>
     */
    private function addPath(array $tree, string $class, array $names): array
    {
        $name = array_shift($names);

        if ($name === null) {
            return $tree;
        }

        $metadata = $this->metadataFactory->getMetadata($class);
        $field = $metadata->field($metadata->property($name));

        if ($field?->relation === null) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a relation of "%s".', $name, $class));
        }

        /** @var array<string, mixed> $relations */
        $relations = $tree[$field->mappedFrom] ?? [];
        $tree[$field->mappedFrom] = $this->addPath($relations, $field->relation, $names);

        return $tree;
    }
}
