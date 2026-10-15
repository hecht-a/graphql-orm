<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Mapping\Fetch;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;
use GraphqlOrm\Query\Expr\FilterExpressionInterface;

/**
 * @internal
 */
final class GraphqlQueryStringBuilder
{
    private string $root;
    /** @var array<string, mixed> */
    private array $arguments = [];
    /** @var string[] */
    private array $fields = [];
    /** @var class-string */
    private string $entityClass;
    private ?bool $manualSelect = false;
    /** @var array<string, bool> */
    private array $visited = [];
    private QueryOptions $options;
    private ?FilterExpressionInterface $filter = null;

    public function __construct(
        private readonly GraphqlManager $manager,
    ) {
        $this->options = new QueryOptions();
    }

    public function root(string $name): self
    {
        $this->root = $name;

        return $this;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function arguments(array $arguments): self
    {
        $this->arguments = $arguments;

        return $this;
    }

    /**
     * @param string[] $fields
     */
    public function fields(array $fields, ?bool $manualSelect = false): self
    {
        $this->fields = $fields;
        $this->manualSelect = $manualSelect;

        return $this;
    }

    /**
     * @param class-string $entityClass
     */
    public function entity(string $entityClass): self
    {
        $this->entityClass = $entityClass;

        return $this;
    }

    public function options(QueryOptions $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function filter(?FilterExpressionInterface $filter): self
    {
        $this->filter = $filter;

        return $this;
    }

    public function build(): QueryNode
    {
        $query = new QueryNode();

        $dialect = $this->manager->getDialect();

        $options = $dialect->applyQueryOptions($this->arguments, $this->options);
        $filter = $dialect->applyFilter($this->filter);
        $args = [...$options, ...$filter];

        $root = new FieldNode(
            $this->root,
            $args,
            new SelectionSetNode(),
        );

        if ($this->manualSelect) {
            $tree = $this->buildSelectionTree($this->fields);
            $selection = $this->buildFromTreeAst($this->entityClass, $tree);
        } else {
            $selection = $this->buildAllFieldsAst($this->entityClass);
        }

        foreach ($selection->fields as $field) {
            $root->selectionSet?->add($field);
        }

        $query->fields[] = $root;

        return $query;
    }

    /**
     * Relations of an entity at or beyond the configured max depth only get their identifier selected.
     *
     * @param class-string $entityClass
     */
    private function buildAllFieldsAst(string $entityClass, int $depth = 0): SelectionSetNode
    {
        $selection = new SelectionSetNode();

        if (isset($this->visited[$entityClass])) {
            return $selection;
        }

        $this->visited[$entityClass] = true;

        try {
            $metadata = $this
                    ->manager
                    ->getMetadataFactory()
                    ->getMetadata($entityClass);

            foreach ($metadata->fields as $field) {
                if ($field->relation !== null) {
                    if ($depth >= $this->manager->getMaxDepth() || $field->fetch === Fetch::Lazy) {
                        $selection->add($this->relationFallbackAst($field));

                        continue;
                    }

                    $nested = $this->buildAllFieldsAst($field->relation, $depth + 1);

                    if ($nested->fields === []) {
                        $selection->add($this->relationFallbackAst($field));

                        continue;
                    }

                    $selection->add(new FieldNode($field->mappedFrom, [], $nested, $field->isCollection));

                    continue;
                }

                $selection->add(new FieldNode($field->mappedFrom, isCollection: $field->isCollection));
            }

            return $selection;
        } finally {
            unset($this->visited[$entityClass]);
        }
    }

    /**
     * @param string[] $fields
     *
     * @return array<string, mixed>
     */
    private function buildSelectionTree(array $fields): array
    {
        /** @var array<string, mixed> $tree */
        $tree = [];

        $metadata = $this->manager->getMetadataFactory()->getMetadata($this->entityClass);

        if ($metadata->identifier !== null) {
            $identifier = $metadata->identifier->mappedFrom;
            $tree[$identifier] ??= [];
        }

        foreach ($fields as $field) {
            $parts = explode('.', $field);

            /** @var array<string, mixed> $current */
            $current = &$tree;

            foreach ($parts as $index => $part) {
                if (!isset($current[$part]) || !\is_array($current[$part])) {
                    $current[$part] = [];
                }

                if ($index === \count($parts) - 1 && \count($parts) === 1) {
                    $current[$part]['__explicit'] = true;
                }

                /** @var array<string, mixed> $current */
                $current = &$current[$part];
            }
        }

        return $tree;
    }

    /**
     * @param class-string $entityClass
     * @param array<mixed> $tree
     */
    private function buildFromTreeAst(string $entityClass, array $tree, ): SelectionSetNode
    {
        $selection = new SelectionSetNode();

        $metadata = $this
            ->manager
            ->getMetadataFactory()
            ->getMetadata($entityClass);

        foreach ($tree as $fieldName => $children) {
            if (!\is_array($children)) {
                continue;
            }

            $fieldName = (string) $fieldName;

            $field = $metadata->field($fieldName);

            if ($field === null) {
                $selection->add(new FieldNode($fieldName));

                continue;
            }

            if ($field->relation === null) {
                $selection->add(new FieldNode($field->mappedFrom, isCollection: $field->isCollection));

                continue;
            }

            $explicit = isset($children['__explicit']);

            unset($children['__explicit']);

            $relationMetadata = $this
                    ->manager
                    ->getMetadataFactory()
                    ->getMetadata($field->relation);

            if ($relationMetadata->identifier !== null) {
                $identifier = $relationMetadata->identifier->mappedFrom;

                $children[$identifier] ??= [];
            }

            if ($explicit) {
                $nested = $this->buildAllFieldsAst($field->relation, depth: 1);

                if ($nested->fields === []) {
                    $selection->add($this->relationFallbackAst($field));

                    continue;
                }

                $selection->add(new FieldNode($field->mappedFrom, [], $nested, $field->isCollection));

                continue;
            }

            if ($this->manualSelect && $children === []) {
                $selection->add($this->relationFallbackAst($field));

                continue;
            }

            $nested = $this->buildFromTreeAst($field->relation, $children);

            if ($nested->fields === []) {
                $selection->add($this->relationFallbackAst($field));

                continue;
            }

            $selection->add(new FieldNode($field->mappedFrom, [], $nested, $field->isCollection));
        }

        return $selection;
    }

    private function relationFallbackAst(GraphqlFieldMetadata $field): FieldNode
    {
        if ($field->relation === null) {
            throw new LogicException('Relation metadata requested on non relation field.');
        }

        $relationMetadata = $this
                ->manager
                ->getMetadataFactory()
                ->getMetadata($field->relation);

        $identifier = $relationMetadata->identifier->mappedFrom ?? 'id';

        $selection = new SelectionSetNode();
        $selection->add(new FieldNode($identifier, isCollection: $field->isCollection));

        return new FieldNode($field->mappedFrom, [], $selection, isCollection: $field->isCollection);
    }
}
